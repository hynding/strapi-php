<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Strapi\Cli\Node\Develop as NodeDevelop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/develop.ts: `$ strapi develop` (alias `dev`).
 *
 * The admin panel is built once with the upstream toolchain (`--build-admin`, default) and the
 * server starts; there is no watch mode for the admin nor an auto-reload of the PHP server
 * (each request boots from the current sources anyway under the built-in server / FPM).
 */
final class Develop extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('develop')
            ->setAliases(['dev'])
            ->addOption('bundler', null, InputOption::VALUE_OPTIONAL, 'Bundler to use (webpack or vite)', 'vite')
            ->addOption('polling', null, InputOption::VALUE_NONE, 'Watch for file changes in network directories')
            ->addOption('watch-admin', null, InputOption::VALUE_NEGATABLE, 'Watch the admin panel for hot changes', true)
            ->addOption('build-admin', null, InputOption::VALUE_NEGATABLE, 'Build the admin panel', true)
            ->addOption('open', null, InputOption::VALUE_NEGATABLE, 'Open the admin in your browser', true)
            ->addOption('install-deps', null, InputOption::VALUE_NEGATABLE, 'Auto-install missing admin dependencies', true)
            ->setDescription('Start your Strapi application in development mode');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('bundler') === 'webpack') {
            $this->ctx->logger->warn('[@strapi/strapi]: Using webpack as a bundler is deprecated. You should migrate to vite.');
        }

        return NodeDevelop::develop([
            'cwd' => $this->ctx->cwd,
            'logger' => $this->ctx->logger,
            'bundler' => (string) $input->getOption('bundler'),
            'buildAdmin' => (bool) $input->getOption('build-admin'),
            'watchAdmin' => (bool) $input->getOption('watch-admin'),
            'open' => (bool) $input->getOption('open'),
            'installDeps' => (bool) $input->getOption('install-deps'),
            'polling' => (bool) $input->getOption('polling'),
        ]);
    }
}
