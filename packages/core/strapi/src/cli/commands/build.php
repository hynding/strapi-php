<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Strapi\Cli\Node\Build as NodeBuild;
use Strapi\Cli\Node\Core\Errors;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Port of packages/core/strapi/src/cli/commands/build.ts: `$ strapi build`. */
final class Build extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('build')
            ->addOption('bundler', null, InputOption::VALUE_OPTIONAL, 'Bundler to use (webpack or vite)', 'vite')
            ->addOption('minify', null, InputOption::VALUE_NEGATABLE, 'Minify the output', true)
            ->addOption('sourcemap', null, InputOption::VALUE_NONE, 'Produce sourcemaps')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Print build statistics to the console')
            ->addOption('install-deps', null, InputOption::VALUE_NONE, 'Auto-install missing admin dependencies')
            ->setDescription('Build the strapi admin app');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($input->getOption('bundler') === 'webpack') {
                $this->ctx->logger->warn('[@strapi/strapi]: Using webpack as a bundler is deprecated. You should migrate to vite.');
            }

            NodeBuild::build([
                'cwd' => $this->ctx->cwd,
                'logger' => $this->ctx->logger,
                'bundler' => (string) $input->getOption('bundler'),
                'minify' => (bool) $input->getOption('minify'),
                'sourcemap' => (bool) $input->getOption('sourcemap'),
                'stats' => (bool) $input->getOption('stats'),
                'installDeps' => (bool) $input->getOption('install-deps'),
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $err) {
            return Errors::handleUnexpectedError($err, $this->ctx->logger);
        }
    }
}
