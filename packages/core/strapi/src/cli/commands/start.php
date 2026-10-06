<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Strapi\Cli\Strapi as CliStrapi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/start.ts: `$ strapi start`.
 *
 * There is no TypeScript `outDir` to resolve: `distDir` is the app directory. The server is PHP's
 * built-in web server running the project's `public/index.php` (see `HttpServer` in strapi/core);
 * a production deployment serves that same file with FPM or FrankenPHP instead.
 */
final class Start extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('start')->setDescription('Start your Strapi application');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $appDir = $this->ctx->cwd;

        CliStrapi::createStrapi(['appDir' => $appDir, 'distDir' => $appDir])->start();

        return Command::SUCCESS;
    }
}
