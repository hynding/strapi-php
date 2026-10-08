<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Symfony\Component\Process\Process;

/**
 * Port of packages/cli/create-strapi-app/src/utils/get-package-manager-args.ts: the install
 * command and environment for npm / yarn / pnpm (which install the admin panel bundle).
 *
 * `execa` becomes an injectable runner `fn (string $command, list<string> $args, array $options):
 * string` returning stdout (tests replace it the way upstream's tests mock execa); the default
 * runs the command with symfony/process.
 *
 * @phpstan-type ExecOptions array{cwd?: string, env?: array<string, string>}
 * @phpstan-type Runner callable(string, list<string>, ExecOptions): string
 */
final class GetPackageManagerArgs
{
    private const INSTALL_ARGUMENTS = ['install'];

    /**
     * Set command line options for specific package managers, with full semver ranges.
     * Do not pass --legacy-peer-deps: a lockfile written under that mode fails strict `npm ci`
     * unless the same flag is set (see #27019). Fresh Strapi apps install cleanly with npm's
     * default peer-dep resolution.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const INSTALL_ARGUMENTS_MAP = [
        'npm' => [
            '*' => [],
        ],
        'yarn' => [
            '<4' => ['--network-timeout', '1000000'],
            '*' => [],
        ],
        'pnpm' => [
            '*' => [],
        ],
    ];

    /**
     * Set environment variables for specific package managers, with full semver ranges.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private const INSTALL_ENV_MAP = [
        'yarn' => [
            '>=4' => ['YARN_HTTP_TIMEOUT' => '1000000'],
            '*' => [],
        ],
        'npm' => [
            '*' => [],
        ],
        'pnpm' => [
            '*' => [],
        ],
    ];

    /**
     * Retrieves the version of the specified package manager (`<pm> --version`).
     *
     * @param ExecOptions $options
     * @param Runner|null $exec
     * @throws \RuntimeException if the package manager's version cannot be determined
     */
    public static function getPackageManagerVersion(string $packageManager, array $options = [], ?callable $exec = null): string
    {
        $exec ??= self::defaultRunner(...);

        try {
            return trim($exec($packageManager, ['--version'], $options));
        } catch (\Throwable $err) {
            throw new \RuntimeException("Error detecting {$packageManager} version: {$err->getMessage()}", 0, $err);
        }
    }

    /**
     * Merges all matching semver ranges, starting with the wildcard `*` entry.
     *
     * @param array<string, list<string>> $versionMap
     * @return list<string>
     */
    private static function mergeMatchingArguments(string $version, array $versionMap): array
    {
        $acc = $versionMap['*'] ?? [];
        foreach ($versionMap as $range => $value) {
            if ($range === '*' || Semver::satisfies($version, $range)) {
                $acc = [...$acc, ...$value];
            }
        }

        return $acc;
    }

    /**
     * Same as {@see self::mergeMatchingArguments()} for environment variables.
     *
     * @param array<string, array<string, string>> $versionMap
     * @return array<string, string>
     */
    private static function mergeMatchingEnvVars(string $version, array $versionMap): array
    {
        $acc = $versionMap['*'] ?? [];
        foreach ($versionMap as $range => $value) {
            if ($range === '*' || Semver::satisfies($version, $range)) {
                $acc = [...$acc, ...$value];
            }
        }

        return $acc;
    }

    /**
     * Retrieves the install arguments and environment variables for a given package manager.
     *
     * @param ExecOptions $options
     * @param Runner|null $exec
     * @return array{envArgs: array<string, string>, cmdArgs: list<string>, version: string}
     * @throws \RuntimeException if the package manager version cannot be determined
     */
    public static function getInstallArgs(string $packageManager, array $options = [], ?callable $exec = null): array
    {
        $packageManagerVersion = self::getPackageManagerVersion($packageManager, $options, $exec);

        // Get environment variables
        $envMap = self::INSTALL_ENV_MAP[$packageManager] ?? ['*' => []];
        $envArgs = $packageManagerVersion !== '' ? self::mergeMatchingEnvVars($packageManagerVersion, $envMap) : $envMap['*'];

        // Get install arguments
        $argsMap = self::INSTALL_ARGUMENTS_MAP[$packageManager] ?? ['*' => []];
        $cmdArgs = $packageManagerVersion !== '' ? self::mergeMatchingArguments($packageManagerVersion, $argsMap) : $argsMap['*'];

        return ['envArgs' => $envArgs, 'cmdArgs' => [...self::INSTALL_ARGUMENTS, ...$cmdArgs], 'version' => $packageManagerVersion];
    }

    /**
     * @param list<string> $args
     * @param ExecOptions $options
     */
    public static function defaultRunner(string $command, array $args, array $options = []): string
    {
        $process = new Process([$command, ...$args], $options['cwd'] ?? null, $options['env'] ?? null, null, 60);
        $process->mustRun();

        return $process->getOutput();
    }
}
