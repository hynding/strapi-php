<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations;

use Strapi\Database\Database;

/**
 * Port of packages/core/database/src/migrations/index.ts (`createMigrationsProvider`): runs the
 * user migrations then the internal ones.
 */
final class Migrations
{
    public Users $users;

    public Internal $internal;

    /** @var list<Users|Internal> */
    private array $providers;

    public function __construct(Database $db)
    {
        $this->users = new Users($db);
        $this->internal = new Internal($db);
        $this->providers = [$this->users, $this->internal];
    }

    public function shouldRun(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->shouldRun()) {
                return true;
            }
        }

        return false;
    }

    public function up(): void
    {
        foreach ($this->providers as $provider) {
            if ($provider->shouldRun()) {
                $provider->up();
            }
        }
    }

    public function down(): void
    {
        foreach ($this->providers as $provider) {
            if ($provider->shouldRun()) {
                $provider->down();
            }
        }
    }
}
