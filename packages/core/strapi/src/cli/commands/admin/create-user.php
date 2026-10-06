<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Admin;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/admin/create-user.ts: `$ strapi admin:create-user`.
 *
 * STUB: needs the admin package (`strapi.admin.services.user` / `role`), which is not ported yet.
 * The options are validated like upstream, then the command explains why it cannot proceed.
 */
final class CreateUser extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('admin:create-user')
            ->setAliases(['admin:create'])
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, 'Email of the new admin')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Password of the new admin')
            ->addOption('firstname', 'f', InputOption::VALUE_REQUIRED, 'First name of the new admin')
            ->addOption('lastname', 'l', InputOption::VALUE_REQUIRED, 'Last name of the new admin')
            ->setDescription('Create a new admin');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');
        $password = $input->getOption('password');
        $firstname = $input->getOption('firstname');

        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>Invalid email address</error>');

            return Command::FAILURE;
        }
        if (is_string($password)) {
            $error = self::validatePassword($password);
            if ($error !== null) {
                $output->writeln("<error>{$error}</error>");

                return Command::FAILURE;
            }
        }
        if (!is_string($firstname) || trim($firstname) === '') {
            $output->writeln('<error>First name is required</error>');

            return Command::FAILURE;
        }

        $app = $this->createStrapi()->load();
        $admin = $app->admin();
        $app->destroy();

        if (!is_array($admin) || !isset($admin['services']['user'])) {
            $output->writeln('<error>admin:create-user needs the admin package (strapi/admin), which is not ported yet.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<error>admin:create-user is not implemented in this edition.</error>');

        return Command::FAILURE;
    }

    /** the `passwordValidator` of upstream create-user.ts */
    public static function validatePassword(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters long';
        }
        if (preg_match('/[a-z]/', $password) !== 1) {
            return 'Password must contain at least one lowercase character';
        }
        if (preg_match('/[A-Z]/', $password) !== 1) {
            return 'Password must contain at least one uppercase character';
        }
        if (preg_match('/\d/', $password) !== 1) {
            return 'Password must contain at least one number';
        }

        return null;
    }
}
