<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\ContentTypes;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Port of packages/core/strapi/src/cli/commands/content-types/list.ts: `$ strapi content-types:list`. */
final class List_ extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('content-types:list')->setDescription('List all the application content-types');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->createStrapi()->register();

        $list = $app->get('content-types')->keys();

        $table = new Table($output);
        $table->setHeaders(['Name']);
        foreach ($list as $name) {
            $table->addRow([$name]);
        }
        $table->render();

        $app->destroy();

        return Command::SUCCESS;
    }
}
