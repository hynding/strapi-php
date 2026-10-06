<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Telemetry;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Port of packages/core/strapi/src/cli/commands/telemetry/disable.ts: `$ strapi telemetry:disable` (no-op, see Enable). */
final class Disable extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('telemetry:disable')->setDescription('Disable anonymous telemetry and metadata sending to Strapi analytics');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        Toggle::set($this->ctx->cwd, true, $output);
        $output->writeln('<info>Successfully opted out of Strapi telemetry</info>');

        return Command::SUCCESS;
    }
}
