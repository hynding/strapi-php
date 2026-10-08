<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Openapi;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/openapi/index.ts: `$ strapi openapi generate
 * [-o, --output <path>]`, here `openapi:generate` (Symfony Console names subcommands with a colon).
 * The action is openapi/generate.php.
 */
final class Openapi extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('openapi:generate')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path for the OpenAPI specification')
            ->setDescription('Generate an OpenAPI specification for the current Strapi application');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('output');

        return Generate::action(
            ['output' => is_string($option) && $option !== '' ? $option : null],
            $this->ctx->cwd,
            $output,
            fn (): \Strapi\Core\Strapi => $this->createStrapi(),
        );
    }
}
