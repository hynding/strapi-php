<?php

declare(strict_types=1);

namespace Strapi\Database\Repairs\Operations;

use Strapi\Database\Database;

/**
 * Port of packages/core/database/src/repairs/operations/process-unidirectional-join-tables.ts:
 * invokes `$operateOnJoinTable` for every unidirectional relation that uses a join table.
 */
final class ProcessUnidirectionalJoinTables
{
    /** @param callable(Database, string, array<string, mixed>, array<string, mixed>): int $operateOnJoinTable */
    public static function processUnidirectionalJoinTables(Database $db, callable $operateOnJoinTable): int
    {
        $totalResult = 0;

        if (count($db->metadata) === 0) {
            return 0;
        }

        $db->logger->debug('Starting unidirectional join table operation');

        foreach ($db->metadata as $model) {
            foreach ($model['attributes'] as $attribute) {
                if (($attribute['type'] ?? null) !== 'relation' || !empty($attribute['inversedBy']) || !empty($attribute['mappedBy'])) {
                    continue;
                }

                if (!empty($attribute['joinTable']) && is_string($attribute['target'] ?? null)) {
                    $totalResult += $operateOnJoinTable($db, $attribute['joinTable']['name'], $attribute, $model);
                }
            }
        }

        $db->logger->debug("Unidirectional join table operation completed. Processed {$totalResult} entries.");

        return $totalResult;
    }
}
