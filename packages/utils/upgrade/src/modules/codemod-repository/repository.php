<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\CodemodRepository;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\Codemod\Constants as CodemodConstants;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Strapi\Upgrade\Modules\Version\Semver;

/**
 * Port of packages/utils/upgrade/src/modules/codemod-repository/repository.ts. The class is
 * `CodemodRepository` (upstream's name; the file is repository.php).
 *
 * @phpstan-import-type FindQuery from Types
 * @phpstan-import-type VersionedCollection from \Strapi\Upgrade\Modules\Codemod\Types
 * @phpstan-import-type Kind from \Strapi\Upgrade\Modules\Codemod\Types
 */
final class CodemodRepository
{
    /** @var array<string, list<Codemod>> */
    private array $groups = [];

    /** @var list<NodeSemVer> */
    private array $versions = [];

    public function __construct(public readonly string $cwd)
    {
        if (!file_exists($cwd)) {
            throw new \RuntimeException("Invalid codemods directory provided \"{$cwd}\"");
        }
    }

    public static function codemodRepositoryFactory(?string $cwd = null): self
    {
        return new self($cwd ?? Constants::internalCodemodsDirectory());
    }

    public function refresh(): self
    {
        $this->refreshAvailableVersions();
        $this->refreshAvailableFiles();

        return $this;
    }

    public function count(NodeSemVer $version): int
    {
        return count($this->findByVersion($version));
    }

    public function versionExists(NodeSemVer $version): bool
    {
        return array_key_exists($version->raw, $this->groups);
    }

    public function has(string $uid): bool
    {
        $result = $this->find(['uids' => [$uid]]);

        if (count($result) !== 1) {
            return false;
        }

        $codemods = $result[0]['codemods'];

        return count($codemods) === 1 && $codemods[0]->uid === $uid;
    }

    /**
     * @param FindQuery $q
     * @return list<VersionedCollection>
     */
    public function find(array $q): array
    {
        $result = [];

        foreach ($this->groups as $version => $codemods) {
            $version = (string) $version;

            // Filter by range if provided in the query
            if (isset($q['range']) && !$q['range']->test($version)) {
                continue;
            }

            // Filter by UID if provided in the query
            if (isset($q['uids'])) {
                $uids = $q['uids'];
                $codemods = array_values(array_filter($codemods, static fn (Codemod $codemod): bool => in_array($codemod->uid, $uids, true)));
            }

            // Only return groups with at least 1 codemod
            if ($codemods !== []) {
                $result[] = ['version' => Semver::semVerFactory($version), 'codemods' => $codemods];
            }
        }

        return $result;
    }

    /** @return list<Codemod> */
    public function findByVersion(NodeSemVer $version): array
    {
        return $this->groups[$version->raw] ?? [];
    }

    /** @return list<VersionedCollection> */
    public function findAll(): array
    {
        $result = [];
        foreach ($this->groups as $version => $codemods) {
            $result[] = ['version' => Semver::semVerFactory((string) $version), 'codemods' => $codemods];
        }

        return $result;
    }

    private function refreshAvailableVersions(): void
    {
        $versions = [];
        foreach (self::readdir($this->cwd) as $filename) {
            // Only keep root directories, whose names are valid semver
            if (is_dir($this->cwd . DIRECTORY_SEPARATOR . $filename) && Semver::isValidSemVer($filename)) {
                $versions[] = Semver::semVerFactory($filename);
            }
        }

        // Sort versions in ascending order
        usort($versions, static fn (NodeSemVer $a, NodeSemVer $b): int => $a->compare($b));

        $this->versions = $versions;
    }

    private function refreshAvailableFiles(): void
    {
        $this->groups = [];

        foreach ($this->versions as $version) {
            $this->refreshAvailableFilesForVersion($version);
        }
    }

    private function refreshAvailableFilesForVersion(NodeSemVer $version): void
    {
        $versionDirectory = $this->cwd . DIRECTORY_SEPARATOR . $version->raw;

        // Ignore obsolete versions
        if (!file_exists($versionDirectory)) {
            return;
        }

        $codemods = [];
        foreach (self::readdir($versionDirectory) as $filename) {
            // Make sure the filenames are valid codemod files
            if (!is_file($versionDirectory . DIRECTORY_SEPARATOR . $filename) || preg_match(CodemodConstants::CODEMOD_FILE_REGEXP, $filename) !== 1) {
                continue;
            }

            // Transform the filenames into Codemod instances
            $codemods[] = Codemod::codemodFactory([
                'kind' => self::parseCodemodKindFromFilename($filename),
                'baseDirectory' => $this->cwd,
                'version' => $version,
                'filename' => $filename,
            ]);
        }

        $this->groups[$version->raw] = $codemods;
    }

    /** @return list<string> `fs.readdirSync` order (sorted by name) */
    private static function readdir(string $directory): array
    {
        $entries = scandir($directory);

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }

    /** @return Kind */
    public static function parseCodemodKindFromFilename(string $filename): string
    {
        $parts = explode('.', $filename);
        $kind = count($parts) >= 2 ? $parts[count($parts) - 2] : null;

        if ($kind === null || !in_array($kind, CodemodConstants::CODEMOD_ALLOWED_SUFFIXES, true)) {
            throw new \UnexpectedValueException("Invalid codemod kind in \"{$filename}\"");
        }

        /** @var Kind $kind */
        return $kind;
    }
}
