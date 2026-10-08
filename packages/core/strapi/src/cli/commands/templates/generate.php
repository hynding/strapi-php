<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Templates;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Port of packages/core/strapi/src/cli/commands/templates/generate.ts: `$ strapi templates:generate <directory>` (deprecated). */
final class Generate extends StrapiCommand
{
    protected bool $requiresProject = false;

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('templates:generate')
            ->addArgument('directory', InputArgument::REQUIRED)
            ->setDescription('(deprecated) Generate template from Strapi project');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        // console.warn
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $err->writeln('This command is deprecated and will be removed in the next major release.');
        $err->writeln('You can now copy an existing app and use it as a template.');

        return Command::SUCCESS;
    }
}
