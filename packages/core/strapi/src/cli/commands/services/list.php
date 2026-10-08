<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Services;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Port of packages/core/strapi/src/cli/commands/services/list.ts: `$ strapi services:list`. */
final class List_ extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('services:list')->setDescription('List all the application services');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->createStrapi()->register();

        $list = $app->get('services')->keys();

        $infoTable = new Table($output);
        $infoTable->setHeaders(['<fg=blue>Name</>']);
        foreach ($list as $name) {
            $infoTable->addRow([$name]);
        }
        $infoTable->render();

        $app->destroy();

        return Command::SUCCESS;
    }
}
