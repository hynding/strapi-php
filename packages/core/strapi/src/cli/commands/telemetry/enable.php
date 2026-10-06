<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Telemetry;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/telemetry/enable.ts: `$ strapi telemetry:enable`.
 *
 * No-op: the telemetry (metrics) service is stubbed in strapi/core and nothing is ever sent, so
 * the flag is only recorded in `composer.json` → `extra.strapi.telemetryDisabled` for parity.
 */
final class Enable extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('telemetry:enable')->setDescription('Enable anonymous telemetry and metadata sending to Strapi analytics');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        Toggle::set($this->ctx->cwd, false, $output);
        $output->writeln('<info>Successfully opted into and enabled Strapi telemetry</info> (no data is sent by the PHP edition)');

        return Command::SUCCESS;
    }
}
