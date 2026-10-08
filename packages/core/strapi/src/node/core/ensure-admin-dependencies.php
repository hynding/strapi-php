<?php

declare(strict_types=1);

namespace Strapi\Cli\Node\Core;

use Strapi\Cli\Cli\Utils\Logger;
use Strapi\Cli\Strapi as CliStrapi;
use Symfony\Component\Process\Process;

/**
 * Port of packages/core/strapi/src/node/core/ensure-admin-dependencies.ts (`handleAdminDependencies`),
 * plus the VERSIONING.md rule: `@strapi/admin` (and `@strapi/strapi`, the admin entry host) in the
 * project's `package.json` must be pinned to the exact version of the `strapi/strapi` Composer
 * package, and the installed copy must match.
 */
final class EnsureAdminDependencies
{
    /** The packages a project must declare for the admin to build (upstream's admin peer deps). */
    public const ADMIN_PEER_DEPS = ['@strapi/admin', '@strapi/strapi', 'react', 'react-dom', 'react-router-dom', 'styled-components'];

    /**
     * @return bool whether to continue (false when dependencies were installed and the command must be re-run)
     */
    public static function handleAdminDependencies(string $cwd, Logger $logger, bool $installIfMissing): bool
    {
        if (getenv('USE_EXPERIMENTAL_DEPENDENCIES') === 'true') {
            $logger->warn('You are using experimental dependencies that may not be compatible with Strapi.');

            return true;
        }

        $packageJson = Plugins::readJson($cwd . '/package.json');
        if ($packageJson === []) {
            throw new \RuntimeException("{$cwd}/package.json not found: the admin panel needs a package.json declaring @strapi/admin (see VERSIONING.md)");
        }

        $deps = [...(is_array($packageJson['devDependencies'] ?? null) ? $packageJson['devDependencies'] : []), ...(is_array($packageJson['dependencies'] ?? null) ? $packageJson['dependencies'] : [])];

        self::assertVersionsMatch($deps);

        $missing = [];
        foreach (self::ADMIN_PEER_DEPS as $name) {
            if (!isset($deps[$name])) {
                $missing[] = ['name' => $name, 'wantedVersion' => str_starts_with($name, '@strapi/') ? CliStrapi::upstreamVersion() : 'latest'];
            } elseif (Plugins::getModule($name, $cwd) === null) {
                $missing[] = ['name' => $name, 'wantedVersion' => (string) $deps[$name]];
            }
        }

        if ($missing !== []) {
            $list = implode("\n", array_map(static fn (array $m): string => "  - {$m['name']}@{$m['wantedVersion']}", $missing));
            if ($installIfMissing) {
                $logger->info("The Strapi admin needs to install the following dependencies:\n{$list}");
                self::installAdminPeerDeps($missing, $cwd, $logger);
                $logger->info('Dependencies installed; run the command again.');

                return false;
            }

            $logger->error("The Strapi admin needs the following dependencies in package.json / node_modules:\n{$list}\nRun `npm install` (or `strapi build --install-deps`).");
            throw new MissingAdminPeerDepsError($missing);
        }

        $installed = Plugins::getModule('@strapi/admin', $cwd);
        $installedVersion = is_string($installed['version'] ?? null) ? $installed['version'] : null;
        if ($installedVersion !== null && $installedVersion !== CliStrapi::upstreamVersion()) {
            throw new \RuntimeException("Installed @strapi/admin is {$installedVersion} but strapi/strapi " . CliStrapi::version() . ' serves ' . CliStrapi::upstreamVersion() . '. Run `npm install` to install the pinned version.');
        }

        return true;
    }

    /**
     * VERSIONING.md: `bin/strapi build` refuses to run when the two manifests disagree.
     *
     * @param array<string, mixed> $deps
     */
    public static function assertVersionsMatch(array $deps): void
    {
        // the npm side is the upstream release: 5.56.0.1 and 5.56.0-beta.1 both serve 5.56.0
        $version = CliStrapi::upstreamVersion();
        foreach (['@strapi/admin', '@strapi/strapi'] as $name) {
            $declared = $deps[$name] ?? null;
            if (!is_string($declared)) {
                continue;
            }
            if (ltrim($declared, '=v') !== $version) {
                throw new \RuntimeException("package.json pins {$name} to \"{$declared}\" but the strapi/strapi Composer package mirrors Strapi {$version}: the admin bundle must match the backend exactly (see VERSIONING.md).");
            }
        }
    }

    /** @param list<array{name: string, wantedVersion: string}> $missing */
    private static function installAdminPeerDeps(array $missing, string $cwd, Logger $logger): void
    {
        $manager = is_file($cwd . '/yarn.lock') ? 'yarn' : (is_file($cwd . '/pnpm-lock.yaml') ? 'pnpm' : 'npm');
        $specs = array_map(static fn (array $m): string => "{$m['name']}@{$m['wantedVersion']}", $missing);
        $command = $manager === 'npm' ? ['npm', 'install', ...$specs] : [$manager, 'add', ...$specs];

        $logger->debug('Running', implode(' ', $command));
        $process = new Process($command, $cwd, null, null, null);
        $process->run(static function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Installing the admin dependencies failed: ' . trim($process->getErrorOutput()));
        }
    }
}
