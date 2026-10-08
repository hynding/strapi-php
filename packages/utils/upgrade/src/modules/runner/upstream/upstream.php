<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Runner\Upstream;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\Report\Types as ReportTypes;
use Strapi\Upgrade\Modules\Runner\AbstractRunner;
use Strapi\Upgrade\Modules\Upgrader\Constants as UpgraderConstants;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PHP-only. A strapi-php project keeps JS/TS sources where upstream does — the admin panel
 * customizations in `src/admin` (React), any `.js`/`.ts` under src/config/public — and upstream's
 * `code` codemods (jscodeshift) apply to them unchanged. This runner hands them to upstream's own
 * tool, `npx @strapi/upgrade codemods run <uid>`, instead of re-implementing jscodeshift:
 *
 * 1. copy the JS/TS files (same relative paths) into a temporary directory, with a minimal
 *    `package.json` pinning `@strapi/strapi` so upstream sees a Strapi application;
 * 2. run the upstream command there (always for real, so a dry run can still report);
 * 3. compare each file: changed → `ok` (copied back unless `dry`), otherwise `nochange`.
 *
 * Without Node.js (`npx` not found) every file is a `skip` and `stats[STAT_UNAVAILABLE]` is set
 * so the codemod runner can warn; a failing command counts every file as an `error`.
 *
 * @phpstan-import-type UpstreamRunnerConfiguration from Types
 * @extends AbstractRunner<UpstreamRunnerConfiguration>
 */
final class UpstreamRunner extends AbstractRunner
{
    /**
     * @param list<string> $paths
     * @param UpstreamRunnerConfiguration $configuration
     */
    public static function upstreamRunnerFactory(array $paths, array $configuration): self
    {
        return new self($paths, $configuration);
    }

    public function valid(Codemod $codemod): bool
    {
        return $codemod->kind === 'code' && $this->paths !== [];
    }

    protected function runner(string $codemodPath, array $paths, array $configuration, Codemod $codemod): array
    {
        $startTime = hrtime(true);
        $report = ReportTypes::empty();
        $cwd = rtrim($configuration['cwd'], DIRECTORY_SEPARATOR);

        $command = $configuration['command'] ?? self::defaultCommand();

        if ($command === null) {
            $report['skip'] = count($paths);
            $report['stats'] = [Types::STAT_UNAVAILABLE => count($paths)];
            $report['timeElapsed'] = self::elapsed($startTime);

            return $report;
        }

        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'strapi-upgrade-' . bin2hex(random_bytes(6));

        try {
            $relatives = [];
            foreach ($paths as $path) {
                $relative = ltrim(substr($path, strlen($cwd)), DIRECTORY_SEPARATOR);
                $target = $tmp . DIRECTORY_SEPARATOR . $relative;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0o777, true);
                }
                copy($path, $target);
                $relatives[$path] = $relative;
            }

            file_put_contents($tmp . '/package.json', json_encode([
                'name' => 'strapi-php-upgrade',
                'version' => '0.0.0',
                'private' => true,
                'dependencies' => [UpgraderConstants::STRAPI_PACKAGE_NAME => $configuration['strapiVersion']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            $process = new Process([...$command, 'codemods', 'run', $codemod->uid, '--project-path', $tmp], $tmp, ['FORCE_COLOR' => '0'], null, $configuration['timeout'] ?? 600.0);
            $process->run();

            if (!$process->isSuccessful()) {
                $report['error'] = count($paths);
                $report['stats'] = ['upstream-exit-code' => (int) $process->getExitCode()];
                $report['timeElapsed'] = self::elapsed($startTime);

                return $report;
            }

            foreach ($relatives as $path => $relative) {
                $after = (string) @file_get_contents($tmp . DIRECTORY_SEPARATOR . $relative);
                if ($after !== '' && $after !== (string) file_get_contents($path)) {
                    if (!($configuration['dry'] ?? false)) {
                        file_put_contents($path, $after);
                    }
                    ++$report['ok'];
                } else {
                    ++$report['nochange'];
                }
            }
        } finally {
            self::remove($tmp);
        }

        $report['timeElapsed'] = self::elapsed($startTime);

        return $report;
    }

    /** @return list<string>|null `npx --yes @strapi/upgrade@<version>`, or null without Node.js */
    public static function defaultCommand(): ?array
    {
        $npx = (new ExecutableFinder())->find('npx');

        return $npx === null ? null : [$npx, '--yes', UpgraderConstants::UPGRADE_NPM_PACKAGE_NAME . '@' . UpgraderConstants::upstreamVersion()];
    }

    private static function elapsed(int|float $startTime): string
    {
        return number_format((hrtime(true) - $startTime) / 1e9, 3, '.', '');
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }
}
