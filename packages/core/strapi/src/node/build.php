<?php

declare(strict_types=1);

namespace Strapi\Cli\Node;

use Strapi\Cli\Node\Core\EnsureAdminDependencies;
use Strapi\Cli\Node\Core\Timer;

/**
 * Port of packages/core/strapi/src/node/build.ts: `$ strapi build` — builds the admin panel of
 * the application with the upstream Node toolchain (vite). There is no TypeScript compile step.
 *
 * @phpstan-type BuildOptions array{cwd: string, logger: \Strapi\Cli\Cli\Utils\Logger, bundler?: string, minify?: bool, sourcemap?: bool, stats?: bool, installDeps?: bool}
 */
final class Build
{
    /** @param BuildOptions $options */
    public static function build(array $options): void
    {
        $logger = $options['logger'];
        $cwd = $options['cwd'];
        $installDeps = $options['installDeps'] ?? false;
        unset($options['logger'], $options['cwd'], $options['installDeps']);

        $timer = Timer::getTimer();

        $shouldContinue = EnsureAdminDependencies::handleAdminDependencies($cwd, $logger, $installDeps);

        if (!$shouldContinue) {
            return;
        }

        $timer->start('createBuildContext');
        $contextSpinner = $logger->spinner('Building build context')->start();

        $ctx = CreateBuildContext::createBuildContext(['cwd' => $cwd, 'logger' => $logger, 'options' => $options]);

        $contextDuration = $timer->end('createBuildContext');
        $contextSpinner->succeed('Building build context (' . Timer::prettyTime($contextDuration) . ')');

        $timer->start('buildAdmin');
        $buildingSpinner = $logger->spinner('Building admin panel')->start();

        try {
            StaticFiles::writeStaticClientFiles($ctx);

            if ($ctx['bundler'] === 'webpack') {
                throw new \RuntimeException('The webpack bundler is not supported by the PHP edition; use vite.');
            }

            Vite\Build::build($ctx);

            $buildDuration = $timer->end('buildAdmin');
            $buildingSpinner->succeed('Building admin panel (' . Timer::prettyTime($buildDuration) . ')');
        } catch (\Throwable $err) {
            $buildingSpinner->fail();
            throw $err;
        }
    }
}
