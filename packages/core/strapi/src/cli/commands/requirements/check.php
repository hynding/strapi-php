<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Requirements;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Strapi\Core\Utils\Requirements;
use Strapi\Types\Core\StrapiDirectories;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not an upstream command: checks that this PHP and project can run Strapi
 * ({@see Requirements}): PHP version, extensions, the database client's PDO driver, writable
 * `.tmp`, `public/uploads` and `.strapi`, the JIT, the `.env.example` secrets in production.
 * Exits 1 when something Strapi cannot run without is missing.
 *
 * The web server's PHP can differ from the command line's (another version, other extensions,
 * its own php.ini): its first request after a deploy logs the same check from there.
 */
final class Check extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('requirements:check')
            ->setDescription('Check that this PHP and project can run Strapi (extensions, writable directories, settings)');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        // a project that cannot even be created is what this command diagnoses: check without it
        $createError = null;
        try {
            $app = $this->createStrapi();
            $client = $app->config()->get('database.connection.client');
            $dirs = $app->dirs();
        } catch (\Throwable $error) {
            $createError = $error;
            $client = $_ENV['DATABASE_CLIENT'] ?? getenv('DATABASE_CLIENT');
            $dirs = StrapiDirectories::fromRoot($this->ctx->cwd);
        }
        $findings = Requirements::check($dirs, is_string($client) && $client !== '' ? $client : null);

        foreach ($findings as $finding) {
            $output->writeln($finding['level'] === 'error' ? "<fg=red>✖ {$finding['message']}</>" : "<fg=yellow>⚠ {$finding['message']}</>");
        }
        if ($findings === []) {
            $output->writeln('<fg=green>✔ PHP ' . PHP_VERSION . ' and this project meet Strapi\'s requirements.</>');
        }
        $output->writeln('<fg=gray>This is the command line\'s PHP (' . (php_ini_loaded_file() ?: 'no php.ini') . '); the web server\'s may differ: its first request after a deploy logs the same check to PHP\'s error log.</>');

        if ($createError !== null) {
            $output->writeln("<fg=red>✖ The project cannot be loaded: {$createError->getMessage()}</>");
        }

        return $createError !== null || Requirements::hasErrors($findings) ? Command::FAILURE : Command::SUCCESS;
    }
}
