<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/package-manager.ts (with get-preferred-pm.ts): which Node
 * package manager a project uses, and `<pm> install`.
 *
 * `preferred-pm` is ported for what it reads from disk: a lockfile in the project
 * (`package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`, `bun.lock(b)`), then one in a parent
 * directory (workspaces), then `node_modules` markers (`.modules.yaml` → pnpm, `.yarn-integrity`
 * → yarn, otherwise npm).
 */
final class PackageManager
{
    public const SUPPORTED_PACKAGE_MANAGERS = ['npm', 'yarn', 'pnpm'];

    public const DEFAULT_PACKAGE_MANAGER = 'npm';

    private const LOCKFILES = [
        'package-lock.json' => 'npm',
        'npm-shrinkwrap.json' => 'npm',
        'yarn.lock' => 'yarn',
        'pnpm-lock.yaml' => 'pnpm',
        'bun.lockb' => 'bun',
        'bun.lock' => 'bun',
    ];

    /** @return 'npm'|'yarn'|'pnpm' */
    public static function getPreferred(string $pkgPath): string
    {
        $pm = self::preferredPM($pkgPath);

        if ($pm === null) {
            throw new \RuntimeException("Couldn't find a package manager in your project.");
        }

        if (!in_array($pm, self::SUPPORTED_PACKAGE_MANAGERS, true)) {
            trigger_error("We detected your package manager ({$pm}), but it's not officially supported by Strapi yet. Defaulting to npm instead.", E_USER_WARNING);

            return self::DEFAULT_PACKAGE_MANAGER;
        }

        /** @var 'npm'|'yarn'|'pnpm' $pm */
        return $pm;
    }

    /**
     * `execa(packageManager, ['install'], { cwd: path, stdin: 'ignore' })`; output goes to the
     * given streams (none: discarded). Throws when the command fails.
     *
     * @param array{stdout?: resource|null, stderr?: resource|null} $options
     */
    public static function installDependencies(string $path, string $packageManager, array $options = []): void
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $null, 'r'],
            1 => $options['stdout'] ?? ['file', $null, 'w'],
            2 => $options['stderr'] ?? ['file', $null, 'w'],
        ];

        $process = proc_open([$packageManager, 'install'], $descriptors, $pipes, $path);
        if ($process === false) {
            throw new \RuntimeException("Could not run {$packageManager} install");
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Command failed with exit code {$exitCode}: {$packageManager} install");
        }
    }

    private static function preferredPM(string $pkgPath): ?string
    {
        foreach (self::LOCKFILES as $file => $pm) {
            if (is_file($pkgPath . DIRECTORY_SEPARATOR . $file)) {
                return $pm;
            }
        }

        // workspaces: a lockfile further up
        $dir = dirname($pkgPath);
        while ($dir !== dirname($dir)) {
            foreach (self::LOCKFILES as $file => $pm) {
                if (is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                    return $pm;
                }
            }
            $dir = dirname($dir);
        }

        $nodeModules = $pkgPath . DIRECTORY_SEPARATOR . 'node_modules';
        if (!is_dir($nodeModules)) {
            return null;
        }

        return match (true) {
            is_file($nodeModules . '/.modules.yaml') => 'pnpm',
            is_file($nodeModules . '/.yarn-integrity') => 'yarn',
            default => 'npm',
        };
    }
}
