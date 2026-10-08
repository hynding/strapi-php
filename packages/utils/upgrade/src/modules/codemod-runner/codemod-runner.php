<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\CodemodRunner;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\CodemodRepository\CodemodRepository;
use Strapi\Upgrade\Modules\CodemodRepository\Constants as CodemodRepositoryConstants;
use Strapi\Upgrade\Modules\Error\Utils as ErrorUtils;
use Strapi\Upgrade\Modules\Format\Formats as F;
use Strapi\Upgrade\Modules\Logger\Logger;
use Strapi\Upgrade\Modules\Project\Project;
use Strapi\Upgrade\Modules\Runner\Upstream\Types as UpstreamTypes;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\Semver;

/**
 * Port of packages/utils/upgrade/src/modules/codemod-runner/codemod-runner.ts.
 *
 * PHP-only: a warning when upstream's tool could not run on the project's JS/TS files.
 *
 * @phpstan-import-type SelectCodemodsCallback from Types
 * @phpstan-import-type CodemodRunnerReport from Types
 * @phpstan-import-type VersionedCollection from \Strapi\Upgrade\Modules\Codemod\Types
 */
class CodemodRunner
{
    private bool $isDry = false;

    private ?Logger $logger = null;

    /** @var SelectCodemodsCallback|null */
    private ?\Closure $selectCodemodsCallback = null;

    public function __construct(private readonly Project $project, private SemverRange $range)
    {
    }

    public static function codemodRunnerFactory(Project $project, SemverRange $range): self
    {
        return new self($project, $range);
    }

    public function range(): SemverRange
    {
        return $this->range;
    }

    public function setRange(SemverRange $range): static
    {
        $this->range = $range;

        return $this;
    }

    public function setLogger(Logger $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    /** @param SelectCodemodsCallback|null $callback */
    public function onSelectCodemods(?\Closure $callback): static
    {
        $this->selectCodemodsCallback = $callback;

        return $this;
    }

    public function dry(bool $enabled = true): static
    {
        $this->isDry = $enabled;

        return $this;
    }

    public function isDry(): bool
    {
        return $this->isDry;
    }

    private function createRepository(?string $codemodsDirectory = null): CodemodRepository
    {
        $repository = CodemodRepository::codemodRepositoryFactory($codemodsDirectory ?? CodemodRepositoryConstants::internalCodemodsDirectory());

        // Make sure we have access to the latest snapshots of codemods on the system
        $repository->refresh();

        return $repository;
    }

    /**
     * @param list<Codemod> $codemods
     * @return CodemodRunnerReport
     */
    private function safeRunAndReport(array $codemods): array
    {
        if ($this->isDry) {
            $this->logger?->warn('Running the codemods in dry mode. No files will be modified during the process.');
        }

        try {
            $reports = $this->project->runCodemods($codemods, ['dry' => $this->isDry]);

            $this->logger?->raw(F::reports($reports));

            foreach ($reports as ['codemod' => $codemod, 'report' => $report]) {
                if (isset($report['stats'][UpstreamTypes::STAT_UNAVAILABLE])) {
                    $this->logger?->warn("Node.js (npx) was not found: {$report['skip']} JS/TS file(s) were not transformed by " . F::codemodUID($codemod->uid) . ". Run `npx @strapi/upgrade codemods run {$codemod->uid}` in the project once Node.js is available.");
                }
            }

            if (!$this->isDry) {
                $nbAffectedTotal = array_sum(array_map(static fn (array $report): int => $report['report']['ok'], $reports));

                $this->logger?->debug('Successfully ran ' . F::highlight(count($codemods)) . ' codemod(s), ' . F::highlight($nbAffectedTotal) . ' change(s) have been detected');
            }

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => ErrorUtils::unknownToError($e)];
        }
    }

    /** @return CodemodRunnerReport */
    public function runByUID(string $uid, ?string $codemodsDirectory = null): array
    {
        $repository = $this->createRepository($codemodsDirectory);

        if (!$repository->has($uid)) {
            throw new \RuntimeException("Unknown codemod UID provided: {$uid}");
        }

        // Note: Ignore the range when running with a UID
        $codemods = array_merge(...array_map(static fn (array $c): array => $c['codemods'], $repository->find(['uids' => [$uid]])));

        return $this->safeRunAndReport($codemods);
    }

    /** @return CodemodRunnerReport */
    public function run(?string $codemodsDirectory = null): array
    {
        $repository = $this->createRepository($codemodsDirectory);

        // Find codemods matching the given range
        $codemodsInRange = $repository->find(['range' => $this->range]);

        // If a selection callback is set, use it, else keep every codemods found
        $selectedCodemods = $this->selectCodemodsCallback !== null ? ($this->selectCodemodsCallback)($codemodsInRange) : $codemodsInRange;

        // If no codemods have been selected (either manually or automatically)
        // Then ignore and return a successful report
        if ($selectedCodemods === []) {
            $this->logger?->debug('Found no codemods to run for ' . F::versionRange($this->range));

            return ['success' => true, 'error' => null];
        }

        // Flatten the collection to a single list of codemods, the original list should already be sorted by version
        $codemods = array_merge(...array_map(static fn (array $c): array => $c['codemods'], $selectedCodemods));

        // Log (debug) the codemods by version
        $codemodsByVersion = [];
        foreach ($codemods as $codemod) {
            $codemodsByVersion[$codemod->version->raw][] = $codemod;
        }

        $this->logger?->debug('Found ' . F::highlight(count($codemods)) . ' codemods for ' . F::highlight(count($codemodsByVersion)) . ' version(s) using ' . F::versionRange($this->range));

        foreach ($codemodsByVersion as $version => $versionCodemods) {
            $this->logger?->debug('- ' . F::version(Semver::semVerFactory((string) $version)) . ' (' . count($versionCodemods) . ')');
        }

        return $this->safeRunAndReport($codemods);
    }
}
