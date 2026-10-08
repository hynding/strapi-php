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
 * The options are validated like upstream, then the super admin is created through the admin
 * package's `admin::user` / `admin::role` services. Without `strapi/admin` installed the command
 * explains why it cannot proceed.
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

        if (!is_array($admin) || !isset($admin['services']['user'])) {
            $app->destroy();
            $output->writeln('<error>admin:create-user needs the admin package (strapi/admin), which is not installed.</error>');

            return Command::FAILURE;
        }

        try {
            $userService = $app->service('admin::user');
            $roleService = $app->service('admin::role');
            $exists = [$userService, 'exists'];
            $create = [$userService, 'create'];
            $getSuperAdmin = [$roleService, 'getSuperAdmin'];
            if (!is_callable($exists) || !is_callable($create) || !is_callable($getSuperAdmin)) {
                throw new \RuntimeException('The admin user and role services are incomplete');
            }

            $user = $exists(['email' => $email]);

            if ($user) {
                $output->writeln("<error>User with email \"{$email}\" already exists</error>");

                return Command::FAILURE;
            }

            $superAdminRole = $getSuperAdmin();

            $create([
                'email' => $email,
                'firstname' => $firstname,
                'lastname' => $input->getOption('lastname'),
                'isActive' => true,
                'roles' => [is_array($superAdminRole) ? ($superAdminRole['id'] ?? null) : null],
                ...(is_string($password) && $password !== '' ? ['password' => $password, 'registrationToken' => null] : []),
            ]);
        } finally {
            $app->destroy();
        }

        $output->writeln('Successfully created new admin');

        return Command::SUCCESS;
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
