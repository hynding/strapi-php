<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Symfony\Component\Process\Process;

/**
 * Port of packages/cli/create-strapi-app/src/utils/git.ts.
 */
final class Git
{
    /** @param list<string> $command */
    private static function run(array $command, ?string $cwd = null): bool
    {
        $process = new Process($command, $cwd, null, null, null);
        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }

    private static function isInGitRepository(string $rootDir): bool
    {
        return self::run(['git', 'rev-parse', '--is-inside-work-tree'], $rootDir);
    }

    private static function isInMercurialRepository(string $rootDir): bool
    {
        return self::run(['hg', '-cwd', '.', 'root'], $rootDir);
    }

    public static function tryGitInit(string $rootDir): bool
    {
        try {
            if (!self::run(['git', '--version'])) {
                return false;
            }
            if (self::isInGitRepository($rootDir) || self::isInMercurialRepository($rootDir)) {
                return false;
            }

            foreach ([['git', 'init'], ['git', 'add', '.'], ['git', 'commit', '-m', 'Initial commit from Strapi']] as $command) {
                if (!self::run($command, $rootDir)) {
                    throw new \RuntimeException('`' . implode(' ', $command) . '` failed');
                }
            }

            return true;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error while trying to initialize git: ' . $e->getMessage() . "\n");

            return false;
        }
    }
}
