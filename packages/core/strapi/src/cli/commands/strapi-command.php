<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Strapi\Cli\Cli\CliContext;
use Strapi\Cli\Cli\Utils\Helpers;
use Strapi\Cli\Strapi as CliStrapi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not an upstream file: the base of every ported command. Upstream commands are
 * `createCommand(name).description().option().action(runAction(name, action))`; here a command is
 * a class with `configure()` for the options and `action()` for the body, and `execute()` applies
 * `runAction` (Strapi-project assertion + error handling) when `$requiresProject` is true.
 */
abstract class StrapiCommand extends Command
{
    protected bool $requiresProject = true;

    public function __construct(protected readonly CliContext $ctx)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable debugging mode with verbose logs');
        $this->addOption('silent', null, InputOption::VALUE_NONE, "Don't log anything");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->requiresProject) {
            return $this->action($input, $output);
        }

        return Helpers::runAction((string) $this->getName(), $this->ctx->cwd, fn (InputInterface $i, OutputInterface $o): int => $this->action($i, $o))($input, $output);
    }

    abstract protected function action(InputInterface $input, OutputInterface $output): int;

    /** `createStrapi(compileStrapi())` on the project root. */
    protected function createStrapi(array $options = []): \Strapi\Core\Strapi
    {
        $appContext = CliStrapi::compileStrapi(['appDir' => $this->ctx->cwd]);

        return CliStrapi::createStrapi([...$appContext, ...$options]);
    }
}
