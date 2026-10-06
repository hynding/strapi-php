<?php

declare(strict_types=1);

namespace Strapi\Types\Modules\Database;

use Strapi\Types\Schema\Schema;

/**
 * The narrow surface core needs from strapi/database. The concrete class
 * Strapi\Database\Database has more (connection, schema, migrations, lifecycles).
 *
 * @phpstan-type FindParams array{select?: list<string>|string, where?: array<string, mixed>, filters?: array<string, mixed>, orderBy?: mixed, limit?: int, offset?: int, page?: int, pageSize?: int, populate?: mixed, count?: bool}
 */
interface Database
{
    /** @param array<string, Schema> $models */
    public function init(array $models): static;

    public function query(string $uid): EntityRepository;

    public function metadata(string $uid): mixed;

    public function transaction(callable $fn): mixed;

    public function destroy(): void;
}
