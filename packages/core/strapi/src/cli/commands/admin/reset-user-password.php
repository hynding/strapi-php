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
 * STUB: needs the admin package (`strapi.admin.services.user.resetPasswordByEmail`), not ported yet.
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
        $app->destroy();

        if (!is_array($admin) || !isset($admin['services']['user'])) {
            $output->writeln('<error>admin:reset-user-password needs the admin package (strapi/admin), which is not ported yet.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<error>admin:reset-user-password is not implemented in this edition.</error>');

        return Command::FAILURE;
    }
}
