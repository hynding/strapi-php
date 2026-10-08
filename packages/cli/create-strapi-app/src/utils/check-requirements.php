<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Port of packages/cli/create-strapi-app/src/utils/check-requirements.ts.
 *
 * The backend is PHP, so the PHP version is the hard requirement ({@see Engines::PHP}). Node only
 * builds the admin panel: upstream's fatal error on an unsupported Node becomes a warning, and a
 * missing Node is reported but does not stop the project from being created.
 */
final class CheckRequirements
{
    public static function checkPhpRequirements(Logger $logger, string $phpVersion = PHP_VERSION): void
    {
        if (!Semver::satisfies($phpVersion, Engines::PHP)) {
            $logger->fatal([
                "<fg=red>You are running <options=bold>PHP {$phpVersion}</></>",
                '<options=bold><fg=green>Strapi requires PHP ' . Engines::PHP . '</></>',
                'Please make sure to use the right version of PHP.',
            ]);
        }
    }

    /**
     * @param string|null $currentNodeVersion the Node version (`process.versions.node`); detected
     *                                        with `node --version` when null
     */
    public static function checkNodeRequirements(Logger $logger, ?string $currentNodeVersion = null): void
    {
        $currentNodeVersion ??= self::detectNodeVersion();
        $engine = Engines::ENGINES['node'];

        if ($currentNodeVersion === null) {
            $logger->warn([
                '<fg=yellow>Node.js was not found</>',
                "The admin panel is built with <options=bold><fg=green>Node.js {$engine}</></>; install it before running <options=bold>php bin/strapi build</>.",
            ]);

            return;
        }

        $nodeMajor = Semver::major($currentNodeVersion);

        // warn if the node version isn't supported (upstream: fatal; here only the admin build needs it)
        if (!Semver::satisfies($currentNodeVersion, $engine)) {
            $logger->warn([
                "<fg=red>You are running <options=bold>Node.js {$currentNodeVersion}</></>",
                "The Strapi admin build requires <options=bold><fg=green>Node.js {$engine}</></>",
                'Please make sure to use the right version of Node.',
            ]);
        } elseif ($nodeMajor < 26 && $nodeMajor % 2 !== 0) {
            // warn if not using a LTS version (odd majors are non-LTS before Node 26)
            $logger->warn([
                "<fg=yellow>You are running <options=bold>Node.js {$currentNodeVersion}</></>",
                'Strapi only supports <options=bold><fg=green>LTS versions of Node.js</></>, other versions may not be compatible.',
            ]);
        }
    }

    private static function detectNodeVersion(): ?string
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            return null;
        }

        $process = new Process([$node, '--version']);
        $process->run();

        return $process->isSuccessful() ? Semver::coerce(trim($process->getOutput())) : null;
    }
}
