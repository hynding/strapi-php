<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Admin;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/admin/reset-user-password.ts: `$ strapi admin:reset-user-password`.
 *
 * Resets the password through the admin package's `admin::user` service (`resetPasswordByEmail`).
 * Without `strapi/admin` installed the command explains why it cannot proceed.
 */
final class ResetUserPassword extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('admin:reset-user-password')
            ->setAliases(['admin:reset-password'])
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, 'The user email')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'New password for the user')
            ->setDescription("Reset an admin user's password");
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');
        $password = $input->getOption('password');

        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>Invalid email address</error>');

            return Command::FAILURE;
        }
        if (!is_string($password) || ($error = CreateUser::validatePassword($password)) !== null) {
            $output->writeln('<error>' . ($error ?? 'Password is required') . '</error>');

            return Command::FAILURE;
        }

        $app = $this->createStrapi()->load();
        $admin = $app->admin();

        if (!is_array($admin) || !isset($admin['services']['user'])) {
            $app->destroy();
            $output->writeln('<error>admin:reset-user-password needs the admin package (strapi/admin), which is not installed.</error>');

            return Command::FAILURE;
        }

        try {
            $resetPasswordByEmail = [$app->service('admin::user'), 'resetPasswordByEmail'];
            if (!is_callable($resetPasswordByEmail)) {
                throw new \RuntimeException('The admin user service has no resetPasswordByEmail()');
            }
            $resetPasswordByEmail($email, $password);
        } catch (\Throwable $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return Command::FAILURE;
        } finally {
            $app->destroy();
        }

        $output->writeln("Successfully reset user's password");

        return Command::SUCCESS;
    }
}
