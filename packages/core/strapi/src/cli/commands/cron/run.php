<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Cron;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not an upstream command. Node keeps `node-schedule` timers alive inside the server process; a
 * PHP backend served by FPM has no long-lived process, so the cron tasks of `config/cron-tasks`
 * (and those registered by plugins) run from the system scheduler:
 *
 *     * * * * * php bin/strapi cron:run
 *
 * runs every task due since the previous call (`.tmp/cron` keeps each task's last run, so a
 * crontab every 5 or 15 minutes, as shared hosts often allow, misses nothing: missed runs
 * coalesce into one, and a task still running in another call is skipped). `--loop` keeps the
 * process alive and polls instead (worker-mode deployments / supervisors), `--list` prints the
 * registered jobs.
 */
final class Run extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('cron:run')
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Keep running and poll for due jobs')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Polling interval in seconds for --loop', '30')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List the registered cron jobs and exit')
            ->setDescription('Run the cron jobs that are due (use from the system crontab under FPM)');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->createStrapi()->load();
        $cron = $app->cron();

        if ($input->getOption('list')) {
            foreach ($cron->jobs() as $job) {
                $next = $job['nextRun']?->format(DATE_ATOM) ?? '-';
                $output->writeln(sprintf('%-40s %-20s next: %s', $job['name'], $job['expression'], $next));
            }
            $app->destroy();

            return Command::SUCCESS;
        }

        // each call is a new process: the jobs' last runs are kept in .tmp/cron (missed runs
        // catch up once, a job running in another call is skipped)
        $cron->persistState($app->dirs()->root . '/.tmp/cron');

        if ($input->getOption('loop')) {
            $cron->loop(max(1, (int) $input->getOption('interval')));
            $app->destroy();

            return Command::SUCCESS;
        }

        $ran = $cron->runDue();
        $output->writeln("Ran {$ran} cron job(s)");

        $app->destroy();

        return Command::SUCCESS;
    }
}
