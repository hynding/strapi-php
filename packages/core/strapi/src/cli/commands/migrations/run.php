<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Migrations;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not an upstream command (upstream runs migrations on every boot; so does this port, in
 * `Strapi::bootstrap()`). `strapi migrations:run` loads the application, which runs the pending
 * user migrations of `database/migrations` and the internal ones, then reports whether anything
 * is still pending — handy in a deploy pipeline before switching traffic.
 */
final class Run extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('migrations:run')->setDescription('Run the pending database migrations (database/migrations and internal)');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->createStrapi()->load();

        $migrations = $app->db()->migrations;
        if ($migrations->shouldRun()) {
            $migrations->up();
        }

        $output->writeln('<info>Migrations are up to date</info>');

        $app->destroy();

        return Command::SUCCESS;
    }
}
