<?php

declare(strict_types=1);

namespace Strapi\Database\Repairs\Operations;

use Strapi\Database\Database;

/**
 * Port of packages/core/database/src/repairs/operations/remove-orphan-morph-types.ts: removes
 * join-table rows whose morph type no longer exists in the metadata.
 */
final class RemoveOrphanMorphTypes
{
    /** @param array{pivot: string} $options */
    public static function removeOrphanMorphType(Database $db, array $options): void
    {
        $pivot = $options['pivot'];
        $db->logger->debug('Removing orphaned morph type: ' . json_encode($pivot));

        foreach ($db->metadata as $model) {
            foreach ($model['attributes'] as $attribute) {
                if (
                    ($attribute['type'] ?? null) !== 'relation'
                    || empty($attribute['joinTable']['name'])
                    || !in_array($pivot, $attribute['joinTable']['pivotColumns'] ?? [], true)
                ) {
                    continue;
                }

                $joinTableName = $attribute['joinTable']['name'];
                $q = $db->connection->quoteSingleIdentifier(...);

                $morphTypes = $db->connection->fetchFirstColumn(sprintf('SELECT DISTINCT %s FROM %s', $q($pivot), $q($joinTableName)));

                foreach ($morphTypes as $morphType) {
                    if ($morphType === null || $db->metadata->has((string) $morphType)) {
                        continue;
                    }

                    $db->logger->debug("Removing invalid morph type \"{$morphType}\" from table \"{$joinTableName}\".");
                    try {
                        $db->connection->executeStatement(sprintf('DELETE FROM %s WHERE %s = ?', $q($joinTableName), $q($pivot)), [$morphType]);
                    } catch (\Throwable $error) {
                        $db->logger->error("Failed to remove invalid morph type \"{$morphType}\" from table \"{$joinTableName}\": {$error->getMessage()}");
                    }
                }
            }
        }
    }
}
