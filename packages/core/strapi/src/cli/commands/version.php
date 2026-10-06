<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Strapi\Cli\Strapi as CliStrapi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Port of packages/core/strapi/src/cli/commands/version.ts: `$ strapi version`. */
final class Version extends StrapiCommand
{
    protected bool $requiresProject = false;

    protected function configure(): void
    {
        parent::configure();
        $this->setName('version')->setDescription('Output the version of Strapi');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(CliStrapi::version());

        return Command::SUCCESS;
    }
}
