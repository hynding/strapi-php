<?php

declare(strict_types=1);

namespace Strapi\Cli\Node;

use Strapi\Cli\Node\Core\EnsureAdminDependencies;
use Strapi\Cli\Node\Core\Timer;
use Strapi\Cli\Strapi as CliStrapi;

/**
 * Port of packages/core/strapi/src/node/develop.ts: `$ strapi develop`.
 *
 * Upstream forks a cluster worker, watches `src/` and restarts the server on change while Vite
 * serves the admin in middleware mode with HMR. The PHP server boots per request (built-in
 * server / FPM), so source changes are picked up without a restart; the admin is built once
 * (`--build-admin`, default) and served statically. `--watch-admin` is accepted but has no
 * effect (no Vite middleware mode).
 *
 * @phpstan-type DevelopOptions array{cwd: string, logger: \Strapi\Cli\Cli\Utils\Logger, bundler?: string, polling?: bool, open?: bool, watchAdmin?: bool, buildAdmin?: bool, installDeps?: bool}
 */
final class Develop
{
    /** @param DevelopOptions $options */
    public static function develop(array $options): int
    {
        $cwd = $options['cwd'];
        $logger = $options['logger'];
        $buildAdmin = $options['buildAdmin'] ?? true;
        $watchAdmin = $options['watchAdmin'] ?? true;
        $installDeps = $options['installDeps'] ?? true;

        $timer = Timer::getTimer();

        if ($watchAdmin) {
            $logger->debug('--watch-admin has no effect in the PHP edition: the admin is built once and served statically');
        }

        if ($buildAdmin) {
            $shouldContinue = EnsureAdminDependencies::handleAdminDependencies($cwd, $logger, $installDeps);
            if (!$shouldContinue) {
                return 0;
            }

            $timer->start('createBuildContext');
            $contextSpinner = $logger->spinner('Building build context')->start();

            $ctx = CreateBuildContext::createBuildContext([
                'cwd' => $cwd,
                'logger' => $logger,
                'options' => ['bundler' => $options['bundler'] ?? 'vite', 'open' => $options['open'] ?? true],
            ]);
            $contextSpinner->succeed('Building build context (' . Timer::prettyTime($timer->end('createBuildContext')) . ')');

            $timer->start('creatingAdmin');
            $adminSpinner = $logger->spinner('Creating admin')->start();

            StaticFiles::writeStaticClientFiles($ctx);
            Vite\Build::build($ctx);

            $adminSpinner->succeed('Creating admin (' . Timer::prettyTime($timer->end('creatingAdmin')) . ')');

            // the build context's instance must not be reused for the server (upstream creates one per worker)
            $ctx['strapi']->destroy();
        }

        $timer->start('loadStrapi');
        $loadStrapiSpinner = $logger->spinner('Loading Strapi')->start();

        $strapi = CliStrapi::createStrapi([
            'appDir' => $cwd,
            'distDir' => $cwd,
            'autoReload' => true,
            'serveAdminPanel' => true,
        ]);

        $strapi->load();

        $loadStrapiSpinner->succeed('Loading Strapi (' . Timer::prettyTime($timer->end('loadStrapi')) . ')');

        $strapi->start();

        return 0;
    }
}
