<?php

declare(strict_types=1);

namespace Strapi\Cli\Node\Vite;

use Symfony\Component\Process\Process;

/**
 * Port of packages/core/strapi/src/node/vite/build.ts: writes the generated Vite config next to
 * the client entry and runs `vite build` with the project's Node toolchain (`node_modules/.bin/vite`,
 * or `npx vite` as a fallback).
 *
 * @phpstan-import-type BuildContext from \Strapi\Cli\Node\CreateBuildContext
 */
final class Build
{
    /** @param BuildContext $ctx */
    public static function build(array $ctx): void
    {
        $configSource = Config::resolveProductionConfig($ctx);
        $configPath = $ctx['runtimeDir'] . '/vite.config.mjs';
        file_put_contents($configPath, $configSource);

        $ctx['logger']->debug('Vite config', $configPath);

        $vite = self::findBinary($ctx['cwd'], 'vite');
        $command = $vite !== null ? [$vite, 'build', '--config', $configPath] : ['npx', '--no-install', 'vite', 'build', '--config', $configPath];

        $process = new Process($command, $ctx['cwd'], [...array_filter(getenv(), 'is_string'), 'NODE_ENV' => 'production'], null, null);
        $process->run(static function (string $type, string $buffer) use ($ctx): void {
            if ($ctx['logger']->isDebug() || $type === Process::ERR) {
                fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('vite build failed (exit code ' . $process->getExitCode() . '): ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /** `node_modules/.bin/<name>` up the directory tree. */
    public static function findBinary(string $cwd, string $name): ?string
    {
        $dir = $cwd;
        while (true) {
            $candidate = $dir . '/node_modules/.bin/' . $name;
            if (is_file($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }
}
