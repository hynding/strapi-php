<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Strapi\Queries\Entity;

/**
 * Port of src/strapi/providers/local-destination/strategies/restore/index.ts: `deleteRecords()`.
 *
 * Restore options (`IRestoreOptions`): `assets` (delete media library files before transfer),
 * `configuration.webhook` / `configuration.coreStore` (delete them before transfer, default true),
 * `entities.include` / `entities.exclude` (uids), `entities.filters` (callables receiving the
 * content type schema), `entities.params` (passed to deleteMany).
 *
 * @phpstan-type RestoreOptions array{assets?: bool|null, configuration?: array{webhook?: bool|null, coreStore?: bool|null}|null, entities?: array{include?: list<string>|null, exclude?: list<string>|null, filters?: list<callable(\Strapi\Types\Schema\Schema): bool>|null, params?: array<string, mixed>|null}|null}
 */
final class Restore
{
    /**
     * @param RestoreOptions $options
     *
     * @return array{count: int, entities: array{count: int, aggregate: array<string, array{count: int}>}, configuration: array{count: int, aggregate: array<string, array{count: int}>}}
     */
    public static function deleteRecords(Strapi $strapi, array $options): array
    {
        $entities = self::deleteEntitiesRecords($strapi, $options);
        $configuration = self::deleteConfigurationRecords($strapi, $options);

        return [
            'count' => $entities['count'] + $configuration['count'],
            'entities' => $entities,
            'configuration' => $configuration,
        ];
    }

    /**
     * @param RestoreOptions $options
     *
     * @return array{count: int, aggregate: array<string, array{count: int}>}
     */
    private static function deleteEntitiesRecords(Strapi $strapi, array $options = []): array
    {
        $entities = $options['entities'] ?? null;

        $models = $strapi->get('models')->get();
        $contentTypes = array_values($strapi->contentTypes());

        $contentTypesToClear = [];
        foreach ($contentTypes as $contentType) {
            $removeThisContentType = true;

            // include means "only include these types" so if it's not in here, it's not being included
            if (isset($entities['include'])) {
                $removeThisContentType = in_array($contentType->uid, $entities['include'], true);
            }

            // if something is excluded, remove it. But lack of being excluded doesn't mean it's kept
            if (isset($entities['exclude']) && in_array($contentType->uid, $entities['exclude'], true)) {
                $removeThisContentType = false;
            }

            if (isset($entities['filters'])) {
                foreach ($entities['filters'] as $filter) {
                    $removeThisContentType = $removeThisContentType && $filter($contentType);
                }
            }

            if ($removeThisContentType) {
                $contentTypesToClear[] = $contentType->uid;
            }
        }

        $modelsToClear = [];
        foreach (is_array($models) ? $models : [] as $model) {
            $uid = is_array($model) ? ($model['uid'] ?? null) : (is_object($model) && isset($model->uid) ? $model->uid : null);
            if (!is_string($uid) || in_array($uid, $contentTypesToClear, true)) {
                continue;
            }

            $removeThisModel = true;

            // include means "only include these types" so if it's not in here, it's not being included
            if (isset($entities['include'])) {
                $removeThisModel = in_array($uid, $entities['include'], true);
            }

            // if something is excluded, remove it. But lack of being excluded doesn't mean it's kept
            if (isset($entities['exclude']) && in_array($uid, $entities['exclude'], true)) {
                $removeThisModel = false;
            }

            if ($removeThisModel) {
                $modelsToClear[] = $uid;
            }
        }

        $results = self::useResults([...$contentTypesToClear, ...$modelsToClear]);

        $contentTypeQuery = Entity::createEntityQuery($strapi);

        foreach ($contentTypesToClear as $uid) {
            $result = $contentTypeQuery($uid)->deleteMany($entities['params'] ?? null);

            if ($result !== null) {
                self::updateResults($results, (int) ($result['count'] ?? 0), $uid);
            }
        }

        foreach ($modelsToClear as $uid) {
            if (!$strapi->db()->metadata->has($uid)) {
                continue;
            }
            $result = $strapi->db()->query($uid)->deleteMany([]);

            self::updateResults($results, $result['count'], $uid);
        }

        return $results;
    }

    /**
     * @param RestoreOptions $options
     *
     * @return array{count: int, aggregate: array<string, array{count: int}>}
     */
    private static function deleteConfigurationRecords(Strapi $strapi, array $options = []): array
    {
        $coreStore = $options['configuration']['coreStore'] ?? true;
        $webhook = $options['configuration']['webhook'] ?? true;

        $models = [];

        if ($coreStore) {
            $models[] = 'strapi::core-store';
        }

        if ($webhook) {
            $models[] = 'strapi::webhook';
        }

        $results = self::useResults($models);

        foreach ($models as $uid) {
            $result = $strapi->db()->query($uid)->deleteMany([]);

            self::updateResults($results, $result['count'], $uid);
        }

        return $results;
    }

    /**
     * @param list<string> $keys
     *
     * @return array{count: int, aggregate: array<string, array{count: int}>}
     */
    private static function useResults(array $keys): array
    {
        $aggregate = [];
        foreach ($keys as $key) {
            $aggregate[$key] = ['count' => 0];
        }

        return ['count' => 0, 'aggregate' => $aggregate];
    }

    /** @param array{count: int, aggregate: array<string, array{count: int}>} $results */
    private static function updateResults(array &$results, int $count, ?string $key = null): void
    {
        if ($key !== null) {
            if (!array_key_exists($key, $results['aggregate'])) {
                throw new ProviderTransferError("Unknown key \"{$key}\" provided in results update");
            }

            $results['aggregate'][$key]['count'] += $count;
        }

        $results['count'] += $count;
    }
}
