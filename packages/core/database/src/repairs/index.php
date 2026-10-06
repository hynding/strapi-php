<?php

declare(strict_types=1);

namespace Strapi\Database\Repairs;

use Strapi\Database\Database;
use Strapi\Database\Repairs\Operations\ProcessUnidirectionalJoinTables;
use Strapi\Database\Repairs\Operations\RemoveOrphanMorphTypes;

/** Port of packages/core/database/src/repairs/index.ts (`createRepairManager`): `db.repair`. */
final class Repairs
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array{pivot: string} $options */
    public function removeOrphanMorphType(array $options): void
    {
        RemoveOrphanMorphTypes::removeOrphanMorphType($this->db, $options);
    }

    /** @param callable(Database, string, array<string, mixed>, array<string, mixed>): int $operateOnJoinTable */
    public function processUnidirectionalJoinTables(callable $operateOnJoinTable): int
    {
        return ProcessUnidirectionalJoinTables::processUnidirectionalJoinTables($this->db, $operateOnJoinTable);
    }
}
