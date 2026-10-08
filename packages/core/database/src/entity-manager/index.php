<?php

declare(strict_types=1);

namespace Strapi\Database\EntityManager;

use Strapi\Database\Database;
use Strapi\Database\Fields\Fields;
use Strapi\Database\Metadata\Relations;
use Strapi\Database\Query\QueryBuilder;
use Strapi\Database\Utils\Types;

/**
 * Port of packages/core/database/src/entity-manager/index.ts.
 *
 * Relation inputs on create/update follow upstream: a plain id, `{ id }`, a list of those, or
 * `{ connect: [...], disconnect: [...], set: [...], options: { strict } }` where connect items may
 * carry `position: { before, after, start, end }`; `{ documentId, locale, status }` connects are
 * resolved to ids first.
 *
 * @phpstan-import-type Meta from \Strapi\Database\Metadata\Metadata
 * @phpstan-type Assocs array{set?: list<array<string, mixed>>|null, connect?: list<array<string, mixed>>, disconnect?: list<array<string, mixed>>, options?: array{strict?: bool|null}}
 */
final class EntityManager
{
    /** @var array<string, EntityRepository> */
    private array $repoMap = [];

    public function __construct(private readonly Database $db)
    {
    }

    // --- helpers -------------------------------------------------------------------------

    /** @phpstan-assert-if-true int|string $value */
    private static function isValidId(mixed $value): bool
    {
        return is_string($value) || is_int($value);
    }

    /**
     * A relation input object: `{ id, __pivot?, position?, __type?, ... }`.
     *
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private static function isIdObject(mixed $value): bool
    {
        return is_array($value) && array_key_exists('id', $value) && self::isValidId($value['id']);
    }

    private static function toId(mixed $value): int|string
    {
        if (is_array($value) && array_key_exists('id', $value) && self::isValidId($value['id'])) {
            return $value['id'];
        }

        if (self::isValidId($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('Invalid id, expected a string or integer, got ' . json_encode($value));
    }

    /** @return list<int|string> */
    private static function toIds(mixed $value): array
    {
        if ($value === null || $value === false) {
            return [];
        }

        return array_map(self::toId(...), is_array($value) && array_is_list($value) ? $value : [$value]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function toIdArray(mixed $data): array
    {
        $list = is_array($data) && array_is_list($data) ? $data : [$data];
        $array = [];
        foreach ($list as $datum) {
            if ($datum === null) {
                continue;
            }

            if (self::isValidId($datum)) {
                $array[] = ['id' => $datum, '__pivot' => []];
                continue;
            }

            if (!self::isIdObject($datum)) {
                throw new \InvalidArgumentException('Invalid id, expected a string or integer, got ' . json_encode($datum));
            }

            $array[] = $datum;
        }

        // uniqWith(isEqual)
        $unique = [];
        foreach ($array as $item) {
            $key = json_encode($item);
            $unique[$key] ??= $item;
        }

        return array_values($unique);
    }

    /**
     * Normalises the relation input into `{ set }` or `{ connect, disconnect, options }`.
     *
     * @return Assocs
     */
    private function toAssocs(mixed $data, ?string $targetUid = null, string $typeField = '__type'): array
    {
        $data = $this->resolveDocumentIds($data, $targetUid);

        if (
            (is_array($data) && array_is_list($data))
            || is_string($data)
            || is_int($data)
            || $data === null
            || (is_array($data) && array_key_exists('id', $data))
        ) {
            return ['set' => $data === null ? null : self::toIdArray($data)];
        }

        if (is_array($data) && array_key_exists('set', $data)) {
            return ['set' => $data['set'] === null ? null : self::toIdArray($data['set'])];
        }

        $connect = [];
        foreach (self::toIdArray($data['connect'] ?? []) as $elm) {
            $connect[] = [
                ...$elm,
                'id' => $elm['id'],
                'position' => !empty($elm['position']) ? $elm['position'] : ['end' => true],
                '__pivot' => $elm['__pivot'] ?? [],
                '__type' => $elm['__type'] ?? $elm[$typeField] ?? null,
            ];
        }

        return [
            'options' => ['strict' => $data['options']['strict'] ?? null],
            'connect' => $connect,
            'disconnect' => self::toIdArray($data['disconnect'] ?? []),
        ];
    }

    /**
     * Document-service style targets (`{ documentId, locale?, status? }`) are resolved to row ids
     * so the database layer can link them.
     */
    private function resolveDocumentIds(mixed $data, ?string $targetUid): mixed
    {
        if ($targetUid === null || !is_array($data)) {
            return $data;
        }

        $resolveItem = function (mixed $item) use ($targetUid): mixed {
            if (!is_array($item) || array_is_list($item) || array_key_exists('id', $item) || !isset($item['documentId'])) {
                return $item;
            }

            $where = ['documentId' => $item['documentId']];
            $meta = $this->db->metadata->get($targetUid);
            if (isset($item['locale']) && isset($meta['attributes']['locale'])) {
                $where['locale'] = $item['locale'];
            }

            $row = null;
            if (isset($meta['attributes']['publishedAt'])) {
                // prefer the requested status (published by default), fall back to the other version
                $status = $item['status'] ?? 'published';
                $order = $status === 'draft' ? [['$null' => true], ['$notNull' => true]] : [['$notNull' => true], ['$null' => true]];
                foreach ($order as $condition) {
                    $row = $this->createQueryBuilder($targetUid)->select('id')->where([...$where, 'publishedAt' => $condition])->first()->execute();
                    if ($row !== null) {
                        break;
                    }
                }
            } else {
                $row = $this->createQueryBuilder($targetUid)->select('id')->where($where)->first()->execute();
            }
            if ($row === null) {
                throw new \InvalidArgumentException("Document with id \"{$item['documentId']}\" not found in {$targetUid}");
            }

            $resolved = $item;
            unset($resolved['documentId'], $resolved['locale'], $resolved['status']);
            $resolved['id'] = $row['id'];

            if (isset($resolved['position']) && is_array($resolved['position'])) {
                foreach (['before', 'after'] as $key) {
                    $anchor = $resolved['position'][$key] ?? null;
                    if (is_array($anchor) && isset($anchor['documentId'])) {
                        $resolvedAnchor = $this->resolveDocumentIds($anchor, $targetUid);
                        $resolved['position'][$key] = $resolvedAnchor['id'];
                    }
                }
            }

            return $resolved;
        };

        if (array_is_list($data)) {
            return array_map($resolveItem, $data);
        }

        if (isset($data['documentId'])) {
            return $resolveItem($data);
        }

        foreach (['set', 'connect', 'disconnect'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = array_map($resolveItem, array_is_list($data[$key]) ? $data[$key] : [$data[$key]]);
            }
        }

        return $data;
    }

    /**
     * @param Meta $metadata
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function processData(array $metadata, array $data = [], bool $withDefaults = false): array
    {
        $attributes = $metadata['attributes'];
        $obj = [];

        foreach ($attributes as $attributeName => $attribute) {
            $attributeName = (string) $attributeName;

            if (Types::isScalarAttribute($attribute)) {
                $field = Fields::createField($attribute);

                if (!array_key_exists($attributeName, $data)) {
                    if (array_key_exists('default', $attribute) && $withDefaults) {
                        $default = $attribute['default'];
                        $obj[$attributeName] = is_callable($default) && !is_string($default) ? $default() : $default;
                    }
                    continue;
                }

                $obj[$attributeName] = $data[$attributeName] === null ? null : $field->toDB($data[$attributeName]);
            }

            if (Types::isRelationalAttribute($attribute)) {
                // oneToOne & manyToOne
                if (!empty($attribute['joinColumn']) && !empty($attribute['owner'])) {
                    $joinColumnName = $attribute['joinColumn']['name'];

                    // allow setting to null
                    $attrValue = array_key_exists($attributeName, $data) ? $data[$attributeName] : ($data[$joinColumnName] ?? null);
                    $has = array_key_exists($attributeName, $data) || array_key_exists($joinColumnName, $data);

                    if (is_array($attrValue) && !array_is_list($attrValue) && isset($attrValue['set']) && is_array($attrValue['set'])) {
                        $setIds = $attrValue['set'];
                        if (count($setIds) > 1) {
                            $this->db->logger->warning("Multiple ids provided for xToOne relation \"{$attributeName}\" stored in a single FK column; keeping only the last id.");
                        }
                        $attrValue = $setIds !== [] ? $setIds[array_key_last($setIds)] : null;
                    }

                    $attrValue = $this->resolveDocumentIds($attrValue, $attribute['target'] ?? null);

                    if ($attrValue === null && $has) {
                        $obj[$joinColumnName] = null;
                    } elseif ($attrValue !== null) {
                        $obj[$joinColumnName] = self::toId($attrValue);
                    }

                    continue;
                }

                if (!empty($attribute['morphColumn']) && !empty($attribute['owner'])) {
                    $idColumn = $attribute['morphColumn']['idColumn'];
                    $typeColumn = $attribute['morphColumn']['typeColumn'];
                    $typeField = $attribute['morphColumn']['typeField'] ?? '__type';

                    if (!array_key_exists($attributeName, $data)) {
                        continue;
                    }

                    $value = $data[$attributeName];

                    if ($value === null) {
                        $obj[$idColumn['name']] = null;
                        $obj[$typeColumn['name']] = null;
                        continue;
                    }

                    if (!is_array($value) || !array_key_exists('id', $value) || !array_key_exists($typeField, $value)) {
                        throw new \InvalidArgumentException("Expects properties {$typeField} an id to make a morph association");
                    }

                    $obj[$idColumn['name']] = $value['id'];
                    $obj[$typeColumn['name']] = $value[$typeField];
                }
            }
        }

        return $obj;
    }

    /**
     * Batched join-table insert; all batches run in the same transaction.
     *
     * @param list<array<string, mixed>> $rows
     * @param array{onConflict?: list<string>, merge?: list<string>, ignore?: bool} $options
     */
    public function insertJoinTableRows(string $joinTableName, array $rows, mixed $trx, array $options = []): void
    {
        if ($rows === []) {
            return;
        }

        $batchSize = $this->db->dialect->getBatchInsertSize();
        foreach (array_chunk($rows, $batchSize) as $chunk) {
            $qb = $this->createQueryBuilder($joinTableName)->insert($chunk)->transacting($trx);
            if (!empty($options['onConflict'])) {
                $qb->onConflict($options['onConflict']);
                if (!empty($options['merge'])) {
                    $qb->merge($options['merge']);
                } elseif (!empty($options['ignore'])) {
                    $qb->ignore();
                }
            }
            $qb->execute();
        }
    }

    // --- read -----------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function findOne(string $uid, array $params = []): ?array
    {
        $props = ['params' => $params];
        $states = $this->db->lifecycles->run('beforeFindOne', $uid, $props);

        $result = $this->createQueryBuilder($uid)->init($props['params'])->first()->execute();

        $props['result'] = $result;
        $this->db->lifecycles->run('afterFindOne', $uid, $props, $states);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public function findMany(string $uid, array $params = []): array
    {
        $props = ['params' => $params];
        $states = $this->db->lifecycles->run('beforeFindMany', $uid, $props);

        $result = $this->createQueryBuilder($uid)->init($props['params'])->execute();

        $props['result'] = $result;
        $this->db->lifecycles->run('afterFindMany', $uid, $props, $states);

        return $result;
    }

    /** @param array<string, mixed> $params */
    public function count(string $uid, array $params = []): int
    {
        $props = ['params' => $params];
        $states = $this->db->lifecycles->run('beforeCount', $uid, $props);

        $res = $this->createQueryBuilder($uid)
            ->init(array_intersect_key($props['params'], array_flip(['_q', 'where', 'filters'])))
            ->count()
            ->first()
            ->execute();

        $result = (int) ($res['count'] ?? 0);

        $props['result'] = $result;
        $this->db->lifecycles->run('afterCount', $uid, $props, $states);

        return $result;
    }

    // --- write ----------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function create(string $uid, array $params = []): array
    {
        $props = ['params' => $params];
        $states = $this->db->lifecycles->run('beforeCreate', $uid, $props);
        $params = $props['params'];

        $metadata = $this->db->metadata->get($uid);
        $data = $params['data'] ?? null;

        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new \InvalidArgumentException('Create expects a data object');
        }

        $dataToInsert = $this->processData($metadata, $data, true);

        $res = $this->createQueryBuilder($uid)->insert($dataToInsert)->execute();
        $id = is_array($res[0] ?? null) ? $res[0]['id'] : ($res[0] ?? null);

        $trx = $this->db->transaction();
        try {
            $this->attachRelations($uid, $id, $data, ['transaction' => $trx->get()]);
            $trx->commit();
        } catch (\Throwable $e) {
            $trx->rollback();
            $this->createQueryBuilder($uid)->where(['id' => $id])->delete()->execute();
            throw $e;
        }

        $result = $this->findOne($uid, array_filter([
            'where' => ['id' => $id],
            'select' => $params['select'] ?? null,
            'populate' => $params['populate'] ?? null,
            'filters' => $params['filters'] ?? null,
        ], static fn (mixed $v): bool => $v !== null));

        $props['result'] = $result;
        $this->db->lifecycles->run('afterCreate', $uid, $props, $states);

        return $result ?? [];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{count: int, ids: list<int|string>}
     */
    public function createMany(string $uid, array $params = []): array
    {
        $props = ['params' => $params];
        $states = $this->db->lifecycles->run('beforeCreateMany', $uid, $props);
        $params = $props['params'];

        $metadata = $this->db->metadata->get($uid);
        $data = $params['data'] ?? null;

        if (!is_array($data) || !array_is_list($data)) {
            throw new \InvalidArgumentException('CreateMany expects data to be an array');
        }

        $dataToInsert = array_map(fn (array $datum): array => $this->processData($metadata, $datum, true), $data);

        if ($dataToInsert === []) {
            throw new \InvalidArgumentException('Nothing to insert');
        }

        $batchSize = $this->db->dialect->getBatchInsertSize();
        $trx = $this->db->transaction();
        $createdEntries = [];
        try {
            foreach (array_chunk($dataToInsert, $batchSize) as $chunk) {
                $chunkResult = $this->createQueryBuilder($uid)->insert($chunk)->transacting($trx->get())->execute();
                array_push($createdEntries, ...(is_array($chunkResult) ? $chunkResult : [$chunkResult]));
            }
            $trx->commit();
        } catch (\Throwable $e) {
            $trx->rollback();
            throw $e;
        }

        $result = [
            'count' => count($data),
            'ids' => array_map(static fn (mixed $entry): mixed => is_array($entry) ? ($entry['id'] ?? null) : $entry, $createdEntries),
        ];

        $props['result'] = $result;
        $this->db->lifecycles->run('afterCreateMany', $uid, $props, $states);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function update(string $uid, array $params = []): ?array
    {
        $props = ['params' => $params];
        $states = $this->db->lifecycles->run('beforeUpdate', $uid, $props);
        $params = $props['params'];

        $metadata = $this->db->metadata->get($uid);
        $where = $params['where'] ?? null;
        $data = $params['data'] ?? null;

        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            throw new \InvalidArgumentException('Update requires a data object');
        }

        if (empty($where)) {
            throw new \InvalidArgumentException('Update requires a where parameter');
        }

        $entity = $this->createQueryBuilder($uid)->select('*')->where($where)->first()->execute(['mapResults' => false]);

        if ($entity === null) {
            return null;
        }

        $id = $entity['id'];

        $dataToUpdate = $this->processData($metadata, $data);

        if ($dataToUpdate !== []) {
            $this->createQueryBuilder($uid)->where(['id' => $id])->update($dataToUpdate)->execute();
        }

        $trx = $this->db->transaction();
        try {
            $this->updateRelations($uid, $id, $data, ['transaction' => $trx->get()]);
            $trx->commit();
        } catch (\Throwable $e) {
            $trx->rollback();
            $this->createQueryBuilder($uid)->where(['id' => $id])->update($entity)->execute();
            throw $e;
        }

        $result = $this->findOne($uid, array_filter([
            'where' => ['id' => $id],
            'select' => $params['select'] ?? null,
            'populate' => $params['populate'] ?? null,
            'filters' => $params['filters'] ?? null,
        ], static fn (mixed $v): bool => $v !== null));

        $props['result'] = $result;
        $this->db->lifecycles->run('afterUpdate', $uid, $props, $states);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{count: int}
     */
    public function updateMany(string $uid, array $params = []): array
    {
        $props = ['params' => $params];
        $states = $this->db->lifecycles->run('beforeUpdateMany', $uid, $props);
        $params = $props['params'];

        $metadata = $this->db->metadata->get($uid);
        $dataToUpdate = $this->processData($metadata, $params['data'] ?? []);

        if ($dataToUpdate === []) {
            throw new \InvalidArgumentException('Update requires data');
        }

        $updatedRows = $this->createQueryBuilder($uid)
            ->init(array_intersect_key($params, array_flip(['_q', 'where', 'filters'])))
            ->update($dataToUpdate)
            ->execute(['mapResults' => false]);

        $result = ['count' => (int) $updatedRows];

        $props['result'] = $result;
        $this->db->lifecycles->run('afterUpdateMany', $uid, $props, $states);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function delete(string $uid, array $params = []): ?array
    {
        $props = ['params' => $params];
        $states = $this->db->lifecycles->run('beforeDelete', $uid, $props);
        $params = $props['params'];

        $where = $params['where'] ?? null;
        $select = $params['select'] ?? null;
        $populate = $params['populate'] ?? null;

        if (empty($where)) {
            throw new \InvalidArgumentException('Delete requires a where parameter');
        }

        $entity = $this->findOne($uid, array_filter([
            'select' => $select !== null ? ['id', ...(is_array($select) ? $select : [$select])] : null,
            'where' => $where,
            'populate' => $populate,
        ], static fn (mixed $v): bool => $v !== null));

        if ($entity === null) {
            return null;
        }

        $id = $entity['id'];

        $this->createQueryBuilder($uid)->where(['id' => $id])->delete()->execute();

        $trx = $this->db->transaction();
        try {
            $this->deleteRelations($uid, $id, ['transaction' => $trx->get()]);
            $trx->commit();
        } catch (\Throwable $e) {
            $trx->rollback();
            throw $e;
        }

        $props['result'] = $entity;
        $this->db->lifecycles->run('afterDelete', $uid, $props, $states);

        return $entity;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{count: int}
     */
    public function deleteMany(string $uid, array $params = []): array
    {
        $props = ['params' => $params];
        $states = $this->db->lifecycles->run('beforeDeleteMany', $uid, $props);
        $params = $props['params'];

        $deletedRows = $this->createQueryBuilder($uid)
            ->init(array_intersect_key($params, array_flip(['_q', 'where', 'filters'])))
            ->delete()
            ->execute(['mapResults' => false]);

        $result = ['count' => (int) $deletedRows];

        $props['result'] = $result;
        $this->db->lifecycles->run('afterDeleteMany', $uid, $props, $states);

        return $result;
    }

    /**
     * Clones an entity: copies its scalar columns and relations (not a Strapi database primitive,
     * but core's document service needs it).
     *
     * @param array<string, mixed> $params `data` overrides, `select`, `populate`
     *
     * @return array<string, mixed>|null
     */
    public function clone(string $uid, int|string $id, array $params = []): ?array
    {
        $meta = $this->db->metadata->get($uid);
        $relationNames = [];
        foreach ($meta['attributes'] as $name => $attribute) {
            if (($attribute['type'] ?? null) === 'relation' && empty($attribute['unstable_virtual'])) {
                $relationNames[] = (string) $name;
            }
        }

        $entity = $this->findOne($uid, ['where' => ['id' => $id], 'populate' => $relationNames]);
        if ($entity === null) {
            return null;
        }

        unset($entity['id']);
        foreach ($relationNames as $name) {
            $value = $entity[$name] ?? null;
            if ($value === null) {
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                $entity[$name] = array_map(static fn (array $r): array => isset($r['__type']) ? ['id' => $r['id'], '__type' => $r['__type']] : (isset($r['__component']) ? ['id' => $r['id'], '__component' => $r['__component']] : ['id' => $r['id']]), $value);
            } elseif (is_array($value) && isset($value['id'])) {
                $entity[$name] = isset($value['__type']) ? ['id' => $value['id'], '__type' => $value['__type']] : ['id' => $value['id']];
            }
        }

        return $this->create($uid, ['data' => [...$entity, ...($params['data'] ?? [])], 'select' => $params['select'] ?? null, 'populate' => $params['populate'] ?? null]);
    }

    // --- relations ------------------------------------------------------------------------

    /**
     * Attach relations to a new entity.
     *
     * @param array<string, mixed> $data
     * @param array{transaction?: mixed} $options
     */
    public function attachRelations(string $uid, int|string $id, array $data, array $options = []): void
    {
        $attributes = $this->db->metadata->get($uid)['attributes'];
        $trx = $options['transaction'] ?? null;

        foreach ($attributes as $attributeName => $attribute) {
            $attributeName = (string) $attributeName;

            $isValidLink = array_key_exists($attributeName, $data) && $data[$attributeName] !== null;

            if (($attribute['type'] ?? null) !== 'relation' || !$isValidLink) {
                continue;
            }

            $cleanRelationData = $this->toAssocs($data[$attributeName], $attribute['target'] ?? null, $attribute['joinTable']['morphColumn']['typeField'] ?? '__type');

            if (in_array($attribute['relation'], ['morphOne', 'morphMany'], true)) {
                $target = $attribute['target'];
                $morphBy = $attribute['morphBy'];

                $targetAttribute = $this->db->metadata->get($target)['attributes'][$morphBy] ?? null;
                if (($targetAttribute['type'] ?? null) !== 'relation') {
                    throw new \InvalidArgumentException("Expected target attribute {$target}.{$morphBy} to be a relation attribute");
                }

                if ($targetAttribute['relation'] === 'morphToOne') {
                    $idColumn = $targetAttribute['morphColumn']['idColumn'];
                    $typeColumn = $targetAttribute['morphColumn']['typeColumn'];

                    $relId = self::toId($cleanRelationData['set'][0] ?? null);

                    $this->createQueryBuilder($target)
                        ->update([$idColumn['name'] => $id, $typeColumn['name'] => $uid])
                        ->where(['id' => $relId])
                        ->transacting($trx)
                        ->execute();
                } elseif ($targetAttribute['relation'] === 'morphToMany') {
                    $joinTable = $targetAttribute['joinTable'];
                    $joinColumn = $joinTable['joinColumn'];
                    $idColumn = $joinTable['morphColumn']['idColumn'];
                    $typeColumn = $joinTable['morphColumn']['typeColumn'];

                    if (empty($cleanRelationData['set'])) {
                        continue;
                    }

                    $rows = [];
                    foreach ($cleanRelationData['set'] as $idx => $datum) {
                        $rows[] = [
                            $joinColumn['name'] => $datum['id'],
                            $idColumn['name'] => $id,
                            $typeColumn['name'] => $uid,
                            ...($joinTable['on'] ?? []),
                            ...($datum['__pivot'] ?? []),
                            'order' => $idx + 1,
                            'field' => $attributeName,
                        ];
                    }

                    $this->insertJoinTableRows($joinTable['name'], $rows, $trx);
                }

                continue;
            }

            if ($attribute['relation'] === 'morphToOne') {
                // handled on the entry itself
                continue;
            }

            if ($attribute['relation'] === 'morphToMany') {
                $joinTable = $attribute['joinTable'];
                $joinColumn = $joinTable['joinColumn'];
                $morphColumn = $joinTable['morphColumn'];
                $idColumn = $morphColumn['idColumn'];
                $typeColumn = $morphColumn['typeColumn'];
                $typeField = $morphColumn['typeField'] ?? '__type';

                if (empty($cleanRelationData['set']) && empty($cleanRelationData['connect'])) {
                    continue;
                }

                // set happens before connect/disconnect
                $dataset = $cleanRelationData['set'] ?? $cleanRelationData['connect'] ?? [];

                $rows = [];
                foreach ($dataset as $idx => $datum) {
                    $rows[] = [
                        $joinColumn['name'] => $id,
                        $idColumn['name'] => $datum['id'],
                        $typeColumn['name'] => $datum[$typeField] ?? $datum['__type'] ?? null,
                        ...($joinTable['on'] ?? []),
                        ...($datum['__pivot'] ?? []),
                        'order' => $idx + 1,
                    ];
                }

                $ordered = RelationsOrderer::create([], $idColumn['name'], 'order', true)
                    ->connect(array_map(fn (array $d): array => MorphRelations::encodePolymorphicRelation('id', $typeField, $d), $dataset))
                    ->get();
                $orderMap = [];
                foreach ($ordered as $idx => $rel) {
                    $orderMap[(string) $rel['id']] = $idx + 1;
                }

                foreach ($rows as &$row) {
                    $encodedId = MorphRelations::encodePolymorphicId($row[$idColumn['name']], (string) $row[$typeColumn['name']]);
                    $row['order'] = $orderMap[$encodedId] ?? $row['order'];
                }
                unset($row);

                MorphRelations::deleteRelatedMorphOneRelationsAfterMorphToManyUpdate($rows, $uid, $attributeName, $joinTable, $this->db, $trx);

                $this->insertJoinTableRows($joinTable['name'], $rows, $trx);

                continue;
            }

            if (!empty($attribute['joinColumn']) && !empty($attribute['owner'])) {
                $relIdsToAdd = self::toIds($cleanRelationData['set'] ?? null);
                if ($attribute['relation'] === 'oneToOne' && Relations::isBidirectional($attribute) && $relIdsToAdd !== []) {
                    $this->createQueryBuilder($uid)
                        ->where([$attribute['joinColumn']['name'] => $relIdsToAdd, 'id' => ['$ne' => $id]])
                        ->update([$attribute['joinColumn']['name'] => null])
                        ->transacting($trx)
                        ->execute();
                }

                continue;
            }

            // oneToOne oneToMany on the non owning side
            if (!empty($attribute['joinColumn']) && empty($attribute['owner'])) {
                $target = $attribute['target'];
                $relIdsToAdd = self::toIds($cleanRelationData['set'] ?? null);

                $this->createQueryBuilder($target)
                    ->where([$attribute['joinColumn']['referencedColumn'] => $id])
                    ->update([$attribute['joinColumn']['referencedColumn'] => null])
                    ->transacting($trx)
                    ->execute();

                $this->createQueryBuilder($target)
                    ->update([$attribute['joinColumn']['referencedColumn'] => $id])
                    ->where(['id' => $relIdsToAdd])
                    ->transacting($trx)
                    ->execute();
            }

            if (!empty($attribute['joinTable'])) {
                $joinTable = $attribute['joinTable'];
                $joinColumn = $joinTable['joinColumn'];
                $inverseJoinColumn = $joinTable['inverseJoinColumn'];
                $orderColumnName = $joinTable['orderColumnName'] ?? null;
                $inverseOrderColumnName = $joinTable['inverseOrderColumnName'] ?? null;

                $relsToAdd = $cleanRelationData['set'] ?? $cleanRelationData['connect'] ?? [];
                $relIdsToadd = self::toIds($relsToAdd);

                if (Relations::isBidirectional($attribute) && Relations::isOneToAny($attribute)) {
                    RegularRelations::deletePreviousOneToAnyRelations($this->db, $id, $attribute, $relIdsToadd, $trx);
                }

                // prepare new relations to insert
                $insert = [];
                foreach (self::uniqById($relsToAdd) as $datum) {
                    $insert[] = [
                        $joinColumn['name'] => $id,
                        $inverseJoinColumn['name'] => $datum['id'],
                        ...($joinTable['on'] ?? []),
                        ...($datum['__pivot'] ?? []),
                    ];
                }

                // add order value
                if (isset($cleanRelationData['set']) && Relations::hasOrderColumn($attribute)) {
                    foreach ($insert as $idx => &$row) {
                        $row[$orderColumnName] = $idx + 1;
                    }
                    unset($row);
                } elseif (isset($cleanRelationData['connect']) && Relations::hasOrderColumn($attribute)) {
                    $ordered = RelationsOrderer::create([], $inverseJoinColumn['name'], $orderColumnName, true)
                        ->connect($relsToAdd)
                        ->get();
                    $orderMap = [];
                    foreach ($ordered as $idx => $rel) {
                        $orderMap[(string) $rel['id']] = $idx;
                    }

                    foreach ($insert as &$row) {
                        $row[$orderColumnName] = $orderMap[(string) $row[$inverseJoinColumn['name']]] ?? null;
                    }
                    unset($row);
                }

                // add inv_order value
                if (Relations::hasInverseOrderColumn($attribute) && $relIdsToadd !== []) {
                    $maxMap = $this->maxInverseOrders($joinTable, $relIdsToadd);

                    foreach ($insert as &$rel) {
                        $rel[$inverseOrderColumnName] = ($maxMap[(string) $rel[$inverseJoinColumn['name']]] ?? 0) + 1;
                    }
                    unset($rel);
                }

                if ($insert === []) {
                    continue;
                }

                $this->insertJoinTableRows($joinTable['name'], $insert, $trx);
            }
        }
    }

    /**
     * Updates relations of an existing entity.
     *
     * @param array<string, mixed> $data
     * @param array{transaction?: mixed} $options
     */
    public function updateRelations(string $uid, int|string $id, array $data, array $options = []): void
    {
        $attributes = $this->db->metadata->get($uid)['attributes'];
        $trx = $options['transaction'] ?? null;

        foreach ($attributes as $attributeName => $attribute) {
            $attributeName = (string) $attributeName;

            if (($attribute['type'] ?? null) !== 'relation' || !array_key_exists($attributeName, $data)) {
                continue;
            }

            $cleanRelationData = $this->toAssocs($data[$attributeName], $attribute['target'] ?? null, $attribute['joinTable']['morphColumn']['typeField'] ?? '__type');

            if (in_array($attribute['relation'], ['morphOne', 'morphMany'], true)) {
                $target = $attribute['target'];
                $morphBy = $attribute['morphBy'];
                $targetAttribute = $this->db->metadata->get($target)['attributes'][$morphBy] ?? [];

                if (($targetAttribute['relation'] ?? null) === 'morphToOne') {
                    $idColumn = $targetAttribute['morphColumn']['idColumn'];
                    $typeColumn = $targetAttribute['morphColumn']['typeColumn'];

                    $this->createQueryBuilder($target)
                        ->update([$idColumn['name'] => null, $typeColumn['name'] => null])
                        ->where([$idColumn['name'] => $id, $typeColumn['name'] => $uid])
                        ->transacting($trx)
                        ->execute();

                    if (($cleanRelationData['set'] ?? null) !== null) {
                        $relIds = self::toIds($cleanRelationData['set'][0] ?? null);
                        if ($relIds !== []) {
                            $this->createQueryBuilder($target)
                                ->update([$idColumn['name'] => $id, $typeColumn['name'] => $uid])
                                ->where(['id' => $relIds])
                                ->transacting($trx)
                                ->execute();
                        }
                    }
                } elseif (($targetAttribute['relation'] ?? null) === 'morphToMany') {
                    $joinTable = $targetAttribute['joinTable'];
                    $joinColumn = $joinTable['joinColumn'];
                    $idColumn = $joinTable['morphColumn']['idColumn'];
                    $typeColumn = $joinTable['morphColumn']['typeColumn'];

                    $hasSet = !empty($cleanRelationData['set']);
                    $connect = $cleanRelationData['connect'] ?? [];
                    $hasConnect = $connect !== [];
                    $hasDisconnect = !empty($cleanRelationData['disconnect']);

                    // for connect/disconnect without a set, only modify those relations
                    if (!$hasSet && ($hasConnect || $hasDisconnect)) {
                        $idsToDelete = [...($cleanRelationData['disconnect'] ?? []), ...($cleanRelationData['connect'] ?? [])];

                        if ($idsToDelete !== []) {
                            $this->createQueryBuilder($joinTable['name'])
                                ->delete()
                                ->where(['$or' => array_map(static fn (array $item): array => [
                                    $idColumn['name'] => $id,
                                    $typeColumn['name'] => $uid,
                                    $joinColumn['name'] => $item['id'],
                                    ...($joinTable['on'] ?? []),
                                    'field' => $attributeName,
                                ], $idsToDelete)])
                                ->transacting($trx)
                                ->execute();
                        }

                        if ($hasConnect) {
                            $start = $this->createQueryBuilder($joinTable['name'])
                                ->where([$idColumn['name'] => $id, $typeColumn['name'] => $uid, ...($joinTable['on'] ?? [])])
                                ->max('order')
                                ->first()
                                ->transacting($trx)
                                ->execute();

                            $startOrder = (float) ($start['max'] ?? 0);

                            $rows = [];
                            foreach ($connect as $idx => $datum) {
                                $rows[] = [
                                    $joinColumn['name'] => $datum['id'],
                                    $idColumn['name'] => $id,
                                    $typeColumn['name'] => $uid,
                                    ...($joinTable['on'] ?? []),
                                    ...($datum['__pivot'] ?? []),
                                    'order' => $startOrder + $idx + 1,
                                    'field' => $attributeName,
                                ];
                            }

                            $this->insertJoinTableRows($joinTable['name'], $rows, $trx);
                        }

                        continue;
                    }

                    // delete all relations
                    $this->createQueryBuilder($joinTable['name'])
                        ->delete()
                        ->where([$idColumn['name'] => $id, $typeColumn['name'] => $uid, ...($joinTable['on'] ?? []), 'field' => $attributeName])
                        ->transacting($trx)
                        ->execute();

                    if ($hasSet) {
                        $rows = [];
                        foreach ($cleanRelationData['set'] ?? [] as $idx => $datum) {
                            $rows[] = [
                                $joinColumn['name'] => $datum['id'],
                                $idColumn['name'] => $id,
                                $typeColumn['name'] => $uid,
                                ...($joinTable['on'] ?? []),
                                ...($datum['__pivot'] ?? []),
                                'order' => $idx + 1,
                                'field' => $attributeName,
                            ];
                        }

                        $this->insertJoinTableRows($joinTable['name'], $rows, $trx);
                    }
                }

                continue;
            }

            if ($attribute['relation'] === 'morphToOne') {
                // handled on the entry itself
                continue;
            }

            if ($attribute['relation'] === 'morphToMany') {
                $this->updateMorphToManyRelation($uid, $id, $attributeName, $attribute, $cleanRelationData, $trx);
                continue;
            }

            if (!empty($attribute['joinColumn']) && !empty($attribute['owner'])) {
                // handled in the row itself
                continue;
            }

            // oneToOne oneToMany on the non owning side.
            if (!empty($attribute['joinColumn']) && empty($attribute['owner'])) {
                $target = $attribute['target'];

                $this->createQueryBuilder($target)
                    ->where([$attribute['joinColumn']['referencedColumn'] => $id])
                    ->update([$attribute['joinColumn']['referencedColumn'] => null])
                    ->transacting($trx)
                    ->execute();

                if (($cleanRelationData['set'] ?? null) !== null) {
                    $relIdsToAdd = self::toIds($cleanRelationData['set']);
                    if ($relIdsToAdd !== []) {
                        $this->createQueryBuilder($target)
                            ->where(['id' => $relIdsToAdd])
                            ->update([$attribute['joinColumn']['referencedColumn'] => $id])
                            ->transacting($trx)
                            ->execute();
                    }
                }
            }

            if (!empty($attribute['joinTable'])) {
                $this->updateJoinTableRelation($uid, $id, $attribute, $cleanRelationData, $trx);
            }
        }
    }

    /**
     * @param array<string, mixed> $attribute
     * @param Assocs $cleanRelationData
     */
    private function updateMorphToManyRelation(string $uid, int|string $id, string $attributeName, array $attribute, array $cleanRelationData, mixed $trx): void
    {
        $joinTable = $attribute['joinTable'];
        $joinColumn = $joinTable['joinColumn'];
        $morphColumn = $joinTable['morphColumn'];
        $idColumn = $morphColumn['idColumn'];
        $typeColumn = $morphColumn['typeColumn'];
        $typeField = $morphColumn['typeField'] ?? '__type';

        $hasSet = !empty($cleanRelationData['set']);
        $connect = $cleanRelationData['connect'] ?? [];
        $hasConnect = $connect !== [];
        $hasDisconnect = !empty($cleanRelationData['disconnect']);

        // for connect/disconnect without a set, only modify those relations
        if (!$hasSet && ($hasConnect || $hasDisconnect)) {
            $idsToDelete = [...($cleanRelationData['disconnect'] ?? []), ...($cleanRelationData['connect'] ?? [])];

            $rowsToDelete = [];
            foreach ([...($cleanRelationData['disconnect'] ?? []), ...($cleanRelationData['connect'] ?? [])] as $idx => $datum) {
                $rowsToDelete[] = [
                    $joinColumn['name'] => $id,
                    $idColumn['name'] => $datum['id'],
                    $typeColumn['name'] => $datum[$typeField] ?? $datum['__type'] ?? null,
                    ...($joinTable['on'] ?? []),
                    ...($datum['__pivot'] ?? []),
                    'order' => $idx + 1,
                ];
            }

            $anchorIds = [];
            foreach ($cleanRelationData['connect'] ?? [] as $r) {
                $anchor = $r['position']['after'] ?? $r['position']['before'] ?? null;
                if ($anchor) {
                    $anchorIds[] = $anchor;
                }
            }

            /** @var list<array<string, mixed>> $adjacentRelations */
            $adjacentRelations = $this->createQueryBuilder($joinTable['name'])
                ->where(['$or' => [
                    [$joinColumn['name'] => $id, $idColumn['name'] => ['$in' => $anchorIds]],
                    [$joinColumn['name'] => $id, 'order' => $this->createQueryBuilder($joinTable['name'])->min('order')->where([$joinColumn['name'] => $id])->where($joinTable['on'] ?? [])->getSqlQuery()],
                    [$joinColumn['name'] => $id, 'order' => $this->createQueryBuilder($joinTable['name'])->max('order')->where([$joinColumn['name'] => $id])->where($joinTable['on'] ?? [])->getSqlQuery()],
                ]])
                ->where($joinTable['on'] ?? [])
                ->orderBy('order')
                ->transacting($trx)
                ->execute();

            if ($idsToDelete !== []) {
                $this->createQueryBuilder($joinTable['name'])
                    ->delete()
                    ->where(['$or' => array_map(static fn (array $item): array => [
                        $idColumn['name'] => $item['id'],
                        $typeColumn['name'] => $item[$typeField] ?? $item['__type'] ?? null,
                        $joinColumn['name'] => $id,
                        ...($joinTable['on'] ?? []),
                    ], $idsToDelete)])
                    ->transacting($trx)
                    ->execute();

                MorphRelations::deleteRelatedMorphOneRelationsAfterMorphToManyUpdate($rowsToDelete, $uid, $attributeName, $joinTable, $this->db, $trx);
            }

            if ($hasConnect) {
                $dataset = $connect;

                $rows = [];
                foreach ($dataset as $datum) {
                    $rows[] = [
                        $joinColumn['name'] => $id,
                        $idColumn['name'] => $datum['id'],
                        $typeColumn['name'] => $datum[$typeField] ?? $datum['__type'] ?? null,
                        ...($joinTable['on'] ?? []),
                        ...($datum['__pivot'] ?? []),
                        'field' => $attributeName,
                    ];
                }

                $orderMap = RelationsOrderer::create(
                    array_map(static fn (array $r): array => MorphRelations::encodePolymorphicRelation($idColumn['name'], $typeColumn['name'], $r), $adjacentRelations),
                    $idColumn['name'],
                    'order',
                    $cleanRelationData['options']['strict'] ?? null,
                )
                    ->connect(array_map(static fn (array $d): array => MorphRelations::encodePolymorphicRelation('id', '__type', $d), $dataset))
                    ->getOrderMap();

                foreach ($rows as &$row) {
                    $encodedId = MorphRelations::encodePolymorphicId($row[$idColumn['name']], (string) $row[$typeColumn['name']]);
                    $row['order'] = $orderMap[$encodedId] ?? null;
                }
                unset($row);

                $this->insertJoinTableRows($joinTable['name'], $rows, $trx);
            }

            return;
        }

        if ($hasSet || ($cleanRelationData['set'] ?? null) === [] || array_key_exists('set', $cleanRelationData)) {
            // delete all relations for this entity
            $this->createQueryBuilder($joinTable['name'])
                ->delete()
                ->where([$joinColumn['name'] => $id, ...($joinTable['on'] ?? [])])
                ->transacting($trx)
                ->execute();
        }

        if ($hasSet) {
            $rows = [];
            foreach ($cleanRelationData['set'] ?? [] as $idx => $datum) {
                $rows[] = [
                    $joinColumn['name'] => $id,
                    $idColumn['name'] => $datum['id'],
                    $typeColumn['name'] => $datum[$typeField] ?? $datum['__type'] ?? null,
                    'field' => $attributeName,
                    ...($joinTable['on'] ?? []),
                    ...($datum['__pivot'] ?? []),
                    'order' => $idx + 1,
                ];
            }

            MorphRelations::deleteRelatedMorphOneRelationsAfterMorphToManyUpdate($rows, $uid, $attributeName, $joinTable, $this->db, $trx);

            $this->insertJoinTableRows($joinTable['name'], $rows, $trx);
        }
    }

    /**
     * @param array<string, mixed> $attribute
     * @param Assocs $cleanRelationData
     */
    private function updateJoinTableRelation(string $uid, int|string $id, array $attribute, array $cleanRelationData, mixed $trx): void
    {
        $joinTable = $attribute['joinTable'];
        $joinColumn = $joinTable['joinColumn'];
        $inverseJoinColumn = $joinTable['inverseJoinColumn'];
        $orderColumnName = $joinTable['orderColumnName'] ?? null;
        $inverseOrderColumnName = $joinTable['inverseOrderColumnName'] ?? null;

        $select = [$joinColumn['name'], $inverseJoinColumn['name']];
        if (Relations::hasOrderColumn($attribute)) {
            $select[] = $orderColumnName;
        }
        if (Relations::hasInverseOrderColumn($attribute)) {
            $select[] = $inverseOrderColumnName;
        }

        // only delete relations
        if (array_key_exists('set', $cleanRelationData) && $cleanRelationData['set'] === null) {
            RegularRelations::deleteRelations($this->db, $id, $attribute, 'all', [], $trx);

            return;
        }

        $isPartialUpdate = !array_key_exists('set', $cleanRelationData);

        if ($isPartialUpdate) {
            $relIdsToaddOrMove = self::toIds($cleanRelationData['connect'] ?? []);

            $connectIds = array_map(static fn (array $c): string => (string) $c['id'], $cleanRelationData['connect'] ?? []);
            $relIdsToDelete = self::toIds(array_values(array_filter(
                $cleanRelationData['disconnect'] ?? [],
                static fn (array $d): bool => !in_array((string) $d['id'], $connectIds, true),
            )));

            $resolvedConnect = $this->resolveDeletedAnchors($id, $attribute, $cleanRelationData['connect'] ?? [], $relIdsToDelete, $trx);

            if ($relIdsToDelete !== []) {
                RegularRelations::deleteRelations($this->db, $id, $attribute, $relIdsToDelete, [], $trx);
            }

            if (empty($cleanRelationData['connect'])) {
                return;
            }

            // Fetch current relations to handle ordering
            $currentMovingRels = [];
            if (Relations::hasOrderColumn($attribute) || Relations::hasInverseOrderColumn($attribute)) {
                $currentMovingRels = $this->createQueryBuilder($joinTable['name'])
                    ->select($select)
                    ->where([$joinColumn['name'] => $id, $inverseJoinColumn['name'] => ['$in' => $relIdsToaddOrMove]])
                    ->where($joinTable['on'] ?? [])
                    ->transacting($trx)
                    ->execute();
            }

            $insert = [];
            foreach (self::uniqById($cleanRelationData['connect']) as $relToAdd) {
                $insert[] = [
                    $joinColumn['name'] => $id,
                    $inverseJoinColumn['name'] => $relToAdd['id'],
                    ...($joinTable['on'] ?? []),
                    ...($relToAdd['__pivot'] ?? []),
                ];
            }

            if (Relations::hasOrderColumn($attribute)) {
                $anchorIds = [];
                foreach ($resolvedConnect as $r) {
                    $anchor = $r['position']['after'] ?? $r['position']['before'] ?? null;
                    if ($anchor) {
                        $anchorIds[] = $anchor;
                    }
                }

                // Get all adjacent relations and the one with the highest order
                $adjacentRelations = $this->createQueryBuilder($joinTable['name'])
                    ->where(['$or' => [
                        [$joinColumn['name'] => $id, $inverseJoinColumn['name'] => ['$in' => $anchorIds]],
                        [$joinColumn['name'] => $id, $orderColumnName => $this->createQueryBuilder($joinTable['name'])->min($orderColumnName)->where([$joinColumn['name'] => $id])->where($joinTable['on'] ?? [])->getSqlQuery()],
                        [$joinColumn['name'] => $id, $orderColumnName => $this->createQueryBuilder($joinTable['name'])->max($orderColumnName)->where([$joinColumn['name'] => $id])->where($joinTable['on'] ?? [])->getSqlQuery()],
                    ]])
                    ->where($joinTable['on'] ?? [])
                    ->orderBy($orderColumnName)
                    ->transacting($trx)
                    ->execute();

                $orderMap = RelationsOrderer::create($adjacentRelations, $inverseJoinColumn['name'], $orderColumnName, $cleanRelationData['options']['strict'] ?? null)
                    ->connect($resolvedConnect)
                    ->getOrderMap();

                foreach ($insert as &$row) {
                    $row[$orderColumnName] = $orderMap[(string) $row[$inverseJoinColumn['name']]] ?? null;
                }
                unset($row);
            }

            // add inv order value
            if (Relations::hasInverseOrderColumn($attribute)) {
                $existingIds = array_map(static fn (array $r): string => (string) $r[$inverseJoinColumn['name']], $currentMovingRels);
                $nonExistingRelsIds = array_values(array_filter($relIdsToaddOrMove, static fn (int|string $rid): bool => !in_array((string) $rid, $existingIds, true)));

                $maxMap = $nonExistingRelsIds === [] ? [] : $this->maxInverseOrders($joinTable, $nonExistingRelsIds);

                foreach ($insert as &$row) {
                    $row[$inverseOrderColumnName] = ($maxMap[(string) $row[$inverseJoinColumn['name']]] ?? 0) + 1;
                }
                unset($row);
            }

            $this->insertJoinTableRows($joinTable['name'], $insert, $trx, [
                'onConflict' => $joinTable['pivotColumns'],
                ...(Relations::hasOrderColumn($attribute) ? ['merge' => [$orderColumnName]] : ['ignore' => true]),
            ]);

            // remove gap between orders
            RegularRelations::cleanOrderColumns($this->db, $attribute, $id, [], $trx);
        } else {
            // overwrite all relations
            $relIdsToaddOrMove = self::toIds($cleanRelationData['set']);
            RegularRelations::deleteRelations($this->db, $id, $attribute, 'all', $relIdsToaddOrMove, $trx);

            if (empty($cleanRelationData['set'])) {
                return;
            }

            $insert = [];
            foreach (self::uniqById($cleanRelationData['set']) as $relToAdd) {
                $insert[] = [
                    $joinColumn['name'] => $id,
                    $inverseJoinColumn['name'] => $relToAdd['id'],
                    ...($joinTable['on'] ?? []),
                    ...($relToAdd['__pivot'] ?? []),
                ];
            }

            if (Relations::hasOrderColumn($attribute)) {
                foreach ($insert as $idx => &$row) {
                    $row[$orderColumnName] = $idx + 1;
                }
                unset($row);
            }

            if (Relations::hasInverseOrderColumn($attribute)) {
                $existingRels = $this->createQueryBuilder($joinTable['name'])
                    ->select([$inverseJoinColumn['name']])
                    ->where([$joinColumn['name'] => $id, $inverseJoinColumn['name'] => ['$in' => $relIdsToaddOrMove]])
                    ->where($joinTable['on'] ?? [])
                    ->transacting($trx)
                    ->execute();

                $inverseRelsIds = array_map(static fn (array $r): string => (string) $r[$inverseJoinColumn['name']], $existingRels);
                $nonExistingRelsIds = array_values(array_filter($relIdsToaddOrMove, static fn (int|string $rid): bool => !in_array((string) $rid, $inverseRelsIds, true)));

                $maxMap = $nonExistingRelsIds === [] ? [] : $this->maxInverseOrders($joinTable, $nonExistingRelsIds);

                foreach ($insert as &$row) {
                    $row[$inverseOrderColumnName] = ($maxMap[(string) $row[$inverseJoinColumn['name']]] ?? 0) + 1;
                }
                unset($row);
            }

            $this->insertJoinTableRows($joinTable['name'], $insert, $trx, [
                'onConflict' => $joinTable['pivotColumns'],
                ...(Relations::hasOrderColumn($attribute) ? ['merge' => [$orderColumnName]] : ['ignore' => true]),
            ]);
        }

        // Delete the previous relations for oneToAny relations
        if (Relations::isBidirectional($attribute) && Relations::isOneToAny($attribute)) {
            RegularRelations::deletePreviousOneToAnyRelations($this->db, $id, $attribute, $relIdsToaddOrMove, $trx);
        }

        // Delete the previous relations for anyToOne relations
        if (Relations::isAnyToOne($attribute)) {
            RegularRelations::deletePreviousAnyToOneRelations($this->db, $id, $attribute, $relIdsToaddOrMove[0] ?? null, $trx);
        }
    }

    /**
     * When a connect item's position.before/after references an id that is about to be deleted,
     * rewrite the position to point at the nearest surviving neighbour.
     *
     * @param array<string, mixed> $attribute
     * @param list<array<string, mixed>> $connect
     * @param list<int|string> $relIdsToDelete
     *
     * @return list<array<string, mixed>>
     */
    private function resolveDeletedAnchors(int|string $id, array $attribute, array $connect, array $relIdsToDelete, mixed $trx): array
    {
        $joinTable = $attribute['joinTable'];
        $joinColumn = $joinTable['joinColumn'];
        $inverseJoinColumn = $joinTable['inverseJoinColumn'];
        $orderColumnName = $joinTable['orderColumnName'] ?? null;

        $deletedIds = array_flip(array_map('strval', $relIdsToDelete));

        $referencesDeleted = false;
        foreach ($connect as $item) {
            $adjacentId = $item['position']['before'] ?? $item['position']['after'] ?? null;
            if ($adjacentId !== null && isset($deletedIds[(string) $adjacentId])) {
                $referencesDeleted = true;
                break;
            }
        }

        if (!Relations::hasOrderColumn($attribute) || $relIdsToDelete === [] || !$referencesDeleted) {
            return $connect;
        }

        $currentOrder = $this->createQueryBuilder($joinTable['name'])
            ->select([$inverseJoinColumn['name'], $orderColumnName])
            ->where([$joinColumn['name'] => $id])
            ->where($joinTable['on'] ?? [])
            ->orderBy($orderColumnName)
            ->transacting($trx)
            ->execute();

        $orderedIds = array_map(static fn (array $rel): mixed => $rel[$inverseJoinColumn['name']], $currentOrder);
        $orderedIdKeys = array_map('strval', $orderedIds);
        $connectIds = array_flip(array_map(static fn (array $item): string => (string) $item['id'], $connect));
        $deletedIdsInCurrentOrder = array_flip(array_values(array_filter($orderedIdKeys, static fn (string $k): bool => isset($deletedIds[$k]))));

        $findSurvivingNeighbor = static function (int|string $targetId, int $direction) use ($orderedIds, $orderedIdKeys, $deletedIds, $connectIds): mixed {
            $index = array_search((string) $targetId, $orderedIdKeys, true);
            while ($index !== false) {
                $index += $direction;
                $candidate = $orderedIds[$index] ?? null;
                if ($candidate === null) {
                    return null;
                }
                $candidateKey = (string) $candidate;
                if (!isset($deletedIds[$candidateKey]) && !isset($connectIds[$candidateKey])) {
                    return $candidate;
                }
            }

            return null;
        };

        $positionByConnectId = [];
        $connectGroups = [];

        foreach ($connect as $item) {
            $before = $item['position']['before'] ?? null;
            $after = $item['position']['after'] ?? null;
            $adjacentId = $before ?? $after;

            if ($adjacentId === null || !isset($deletedIds[(string) $adjacentId]) || !isset($deletedIdsInCurrentOrder[(string) $adjacentId])) {
                continue;
            }

            $key = (string) $adjacentId;
            $connectGroups[$key] ??= ['targetId' => $adjacentId, 'before' => [], 'after' => []];
            if ($before !== null) {
                $connectGroups[$key]['before'][] = $item;
            } else {
                $connectGroups[$key]['after'][] = $item;
            }
        }

        foreach ($connectGroups as ['targetId' => $targetId, 'before' => $before, 'after' => $after]) {
            $previousNeighbor = $findSurvivingNeighbor($targetId, -1);
            $nextNeighbor = $findSurvivingNeighbor($targetId, 1);
            $previousPositionId = $previousNeighbor;

            foreach ([...$before, ...$after] as $item) {
                if ($previousPositionId !== null) {
                    $positionByConnectId[(string) $item['id']] = ['after' => $previousPositionId];
                } elseif ($nextNeighbor !== null) {
                    $positionByConnectId[(string) $item['id']] = ['before' => $nextNeighbor];
                } else {
                    $positionByConnectId[(string) $item['id']] = ['start' => true];
                }

                $previousPositionId = $item['id'];
            }
        }

        return array_map(static function (array $item) use ($positionByConnectId): array {
            $position = $positionByConnectId[(string) $item['id']] ?? null;

            return $position !== null ? [...$item, 'position' => $position] : $item;
        }, $connect);
    }

    /**
     * Max inverse order per target id, for computing `inv_order` of new rows.
     *
     * @param array<string, mixed> $joinTable
     * @param list<int|string> $ids
     *
     * @return array<string, float>
     */
    private function maxInverseOrders(array $joinTable, array $ids): array
    {
        $inverseJoinColumn = $joinTable['inverseJoinColumn'];
        $inverseOrderColumnName = $joinTable['inverseOrderColumnName'];

        $rows = $this->db->sql()
            ->select([$inverseJoinColumn['name']])
            ->max($inverseOrderColumnName, 'max')
            ->from($joinTable['name'])
            ->whereIn($inverseJoinColumn['name'], $ids)
            ->where($joinTable['on'] ?? [])
            ->groupBy([$inverseJoinColumn['name']])
            ->rows();

        $maxMap = [];
        foreach ($rows as $res) {
            $maxMap[(string) $res[$inverseJoinColumn['name']]] = (float) $res['max'];
        }

        return $maxMap;
    }

    /**
     * Delete relational associations of an existing entity (not cascade deletions).
     *
     * @param array{transaction?: mixed} $options
     */
    public function deleteRelations(string $uid, int|string $id, array $options = []): void
    {
        $attributes = $this->db->metadata->get($uid)['attributes'];
        $trx = $options['transaction'] ?? null;

        foreach ($attributes as $attributeName => $attribute) {
            $attributeName = (string) $attributeName;

            if (($attribute['type'] ?? null) !== 'relation') {
                continue;
            }

            if (in_array($attribute['relation'], ['morphOne', 'morphMany'], true)) {
                $target = $attribute['target'];
                $morphBy = $attribute['morphBy'];
                $targetAttribute = $this->db->metadata->get($target)['attributes'][$morphBy] ?? [];

                if (($targetAttribute['relation'] ?? null) === 'morphToOne') {
                    $idColumn = $targetAttribute['morphColumn']['idColumn'];
                    $typeColumn = $targetAttribute['morphColumn']['typeColumn'];

                    $this->createQueryBuilder($target)
                        ->update([$idColumn['name'] => null, $typeColumn['name'] => null])
                        ->where([$idColumn['name'] => $id, $typeColumn['name'] => $uid])
                        ->transacting($trx)
                        ->execute();
                } elseif (($targetAttribute['relation'] ?? null) === 'morphToMany') {
                    $joinTable = $targetAttribute['joinTable'];
                    $idColumn = $joinTable['morphColumn']['idColumn'];
                    $typeColumn = $joinTable['morphColumn']['typeColumn'];

                    $this->createQueryBuilder($joinTable['name'])
                        ->delete()
                        ->where([$idColumn['name'] => $id, $typeColumn['name'] => $uid, ...($joinTable['on'] ?? []), 'field' => $attributeName])
                        ->transacting($trx)
                        ->execute();
                }

                continue;
            }

            if ($attribute['relation'] === 'morphToMany') {
                $joinTable = $attribute['joinTable'];
                $joinColumn = $joinTable['joinColumn'];

                $this->createQueryBuilder($joinTable['name'])
                    ->delete()
                    ->where([$joinColumn['name'] => $id, ...($joinTable['on'] ?? [])])
                    ->transacting($trx)
                    ->execute();

                continue;
            }

            // do not need to delete links when using foreign keys
            if ($this->db->dialect->usesForeignKeys()) {
                continue;
            }

            if (!empty($attribute['joinColumn']) && !empty($attribute['owner'])) {
                continue;
            }

            // oneToOne oneToMany on the non owning side.
            if (!empty($attribute['joinColumn']) && empty($attribute['owner'])) {
                $target = $attribute['target'];

                $this->createQueryBuilder($target)
                    ->where([$attribute['joinColumn']['referencedColumn'] => $id])
                    ->update([$attribute['joinColumn']['referencedColumn'] => null])
                    ->transacting($trx)
                    ->execute();
            }

            if (!empty($attribute['joinTable'])) {
                RegularRelations::deleteRelations($this->db, $id, $attribute, 'all', [], $trx);
            }
        }
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @return array<string, mixed>
     */
    public function populate(string $uid, array $entity, mixed $populate): array
    {
        $entry = $this->findOne($uid, ['select' => ['id'], 'where' => ['id' => $entity['id']], 'populate' => $populate]);

        return [...$entity, ...($entry ?? [])];
    }

    /**
     * @param array<string, mixed> $entity
     * @param string|list<string> $fields
     */
    public function load(string $uid, array $entity, string|array $fields, mixed $populate = null): mixed
    {
        $attributes = $this->db->metadata->get($uid)['attributes'];

        $fieldsArr = is_array($fields) ? $fields : [$fields];
        foreach ($fieldsArr as $field) {
            if (($attributes[$field]['type'] ?? null) !== 'relation') {
                throw new \InvalidArgumentException("Invalid load. Expected {$field} to be a relational attribute");
            }
        }

        $populateMap = [];
        foreach ($fieldsArr as $field) {
            $populateMap[$field] = $populate ?? true;
        }

        $entry = $this->findOne($uid, ['select' => ['id'], 'where' => ['id' => $entity['id']], 'populate' => $populateMap]);

        if ($entry === null) {
            return null;
        }

        if (is_array($fields)) {
            return array_intersect_key($entry, array_flip($fields));
        }

        return $entry[$fields] ?? null;
    }

    public function createQueryBuilder(string $uid): QueryBuilder
    {
        return new QueryBuilder($uid, $this->db);
    }

    public function getRepository(string $uid): EntityRepository
    {
        return $this->repoMap[$uid] ??= new EntityRepository($uid, $this->db);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private static function uniqById(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = (string) $item['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }
}
