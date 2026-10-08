<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Strapi\Queries\Entity;
use Strapi\DataTransfer\Utils\Components;
use Strapi\DataTransfer\Utils\Stream\Writable;
use Strapi\DataTransfer\Utils\Transaction;

/**
 * Port of src/strapi/providers/local-destination/strategies/restore/entities.ts.
 */
final class Entities
{
    /**
     * @param array{strapi: Strapi, updateMappingTable: callable(string, int, int): void, transaction?: Transaction|null} $options
     */
    public static function createEntitiesWriteStream(array $options): Writable
    {
        $strapi = $options['strapi'];
        $updateMappingTable = $options['updateMappingTable'];
        $transaction = $options['transaction'] ?? null;
        $query = Entity::createEntityQuery($strapi);

        return new Writable(write: static function (mixed $entity) use ($strapi, $updateMappingTable, $transaction, $query): void {
            $entity = is_array($entity) ? $entity : [];

            $run = static function () use ($strapi, $updateMappingTable, $query, $entity): void {
                $type = (string) ($entity['type'] ?? '');
                $id = $entity['id'] ?? null;
                $data = is_array($entity['data'] ?? null) ? $entity['data'] : [];
                $entityQuery = $query($type);
                $contentType = $strapi->getModel($type);

                try {
                    if ($contentType === null) {
                        throw new \RuntimeException("Model {$type} not found");
                    }

                    $created = $entityQuery->create([
                        'data' => $data,
                        'populate' => $entityQuery->getDeepPopulateComponentLikeQuery($contentType, ['select' => 'id']),
                        'select' => 'id',
                    ]);

                    $updateMappingTable($type, (int) $id, (int) $created['id']);

                    // Register the ID of every component instance that was re-created
                    // with the entity — including the ones whose ID did not change — so
                    // that the links stage can resolve component-side references and
                    // tell transferred components apart from missing/orphaned ones
                    $componentMappings = Components::collectComponentIdMappings([
                        'data' => $data,
                        'created' => $created,
                        'schema' => $contentType,
                        'strapi' => $strapi,
                    ]);

                    foreach ($componentMappings as $mapping) {
                        $updateMappingTable($mapping['uid'], $mapping['oldID'], $mapping['newID']);
                    }
                } catch (ProviderTransferError $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    throw $e instanceof \Exception ? $e : new ProviderTransferError("Failed to create \"{$type}\" (" . (is_scalar($id) ? (string) $id : '') . ')');
                }
            };

            $transaction?->attach($run);
        });
    }
}
