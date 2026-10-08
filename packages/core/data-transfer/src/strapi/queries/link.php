<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Queries;

use Doctrine\DBAL\Connection;
use Strapi\Core\Strapi;

/**
 * Port of src/strapi/queries/link.ts: `createLinkQuery(strapi, trx, { onOrphanedLink })` returns
 * `() => query`, and that query is an instance of this class (`generateAll`,
 * `generateAllForAttribute`, `insert`). Knex's query builder becomes plain SQL on the DBAL
 * connection (the open transaction, when there is one, lives on that connection).
 *
 * A link is `['kind' => 'relation.basic'|'relation.morph'|'relation.circular', 'relation' => string,
 * 'left' => ['type', 'ref', 'field', 'pos'?], 'right' => ['type', 'ref', 'field'?, 'pos'?]]`.
 *
 * @phpstan-type LinkData array{kind: string, relation: string, left: array<string, mixed>, right: array<string, mixed>}
 */
final class Link
{
    private const string TARGET_EXISTS_ALIAS = '__target_exists';

    private const string LEFT_EXISTS_ALIAS = '__left_exists';

    private const string RIGHT_EXISTS_ALIAS = '__right_exists';

    private const int EXISTENCE_CHECK_CHUNK_SIZE = 1000;

    /** @param (\Closure(array<string, mixed>): void)|null $onOrphanedLink */
    private function __construct(private readonly Strapi $strapi, private readonly ?\Closure $onOrphanedLink)
    {
    }

    /**
     * @param array{onOrphanedLink?: callable(array<string, mixed>): void} $options
     *
     * @return \Closure(): self
     */
    public static function createLinkQuery(Strapi $strapi, ?Connection $trx = null, array $options = []): \Closure
    {
        $onOrphanedLink = isset($options['onOrphanedLink']) ? \Closure::fromCallable($options['onOrphanedLink']) : null;

        // `trx`: the transaction lives on the connection (see Strapi\Database\TransactionContext)
        return static fn (): self => new self($strapi, $onOrphanedLink);
    }

    private function connection(): Connection
    {
        return $this->strapi->db()->getConnection();
    }

    /** @return array<string, mixed> */
    private function metadata(string $uid): array
    {
        if (!$this->strapi->db()->metadata->has($uid)) {
            throw new \RuntimeException("No metadata found for {$uid}");
        }

        return $this->strapi->db()->metadata->get($uid);
    }

    private function getMetadataTableName(string $uid): string
    {
        return (string) $this->metadata($uid)['tableName'];
    }

    /** TODO: Export utils from database and use the addSchema that is already written */
    private function addSchema(string $tableName): string
    {
        $schemaName = $this->strapi->db()->getSchemaName();

        return $schemaName !== null ? "{$schemaName}.{$tableName}" : $tableName;
    }

    /** Quote a (possibly schema-qualified / alias-qualified) identifier part by part. */
    private function q(string $identifier): string
    {
        $connection = $this->connection();

        return implode('.', array_map(static fn (string $part): string => $connection->quoteSingleIdentifier($part), explode('.', $identifier)));
    }

    private static function refKey(int|string $ref): string
    {
        return (is_int($ref) ? 'number' : 'string') . ":{$ref}";
    }

    /** Normalize a raw row value used as a reference: numeric ids come back as strings from some drivers. */
    private static function toRef(mixed $value, bool $numeric = true): int|string|null
    {
        if ($value === null) {
            return null;
        }
        if ($numeric && (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1))) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return is_scalar($value) ? (is_int($value) ? $value : (string) $value) : null;
    }

    /**
     * Batch-load which refs exist for a content type. Handles numeric `id` refs and
     * string `documentId` refs separately, chunked to keep `IN (...)` lists bounded.
     *
     * @param list<int|string> $refs
     *
     * @return array<string, true>
     */
    private function loadExistingRefs(string $uid, array $refs): array
    {
        $existing = [];

        // Stale morph type UIDs (removed components/CTs) have no metadata — treat as orphaned.
        if (!$this->strapi->db()->metadata->has($uid)) {
            return $existing;
        }

        $uniqueRefs = array_values(array_unique($refs, SORT_REGULAR));
        $numericIds = array_values(array_filter($uniqueRefs, 'is_int'));
        $documentIds = array_values(array_filter($uniqueRefs, 'is_string'));

        foreach (array_chunk($numericIds, self::EXISTENCE_CHECK_CHUNK_SIZE) as $chunk) {
            $rows = $this->strapi->db()->query($uid)->findMany([
                'select' => ['id'],
                'where' => ['id' => ['$in' => $chunk]],
            ]);

            foreach ($rows as $row) {
                $existing[self::refKey((int) $row['id'])] = true;
            }
        }

        foreach (array_chunk($documentIds, self::EXISTENCE_CHECK_CHUNK_SIZE) as $chunk) {
            $rows = $this->strapi->db()->query($uid)->findMany([
                'select' => ['id', 'documentId'],
                'where' => ['documentId' => ['$in' => $chunk]],
            ]);

            foreach ($rows as $row) {
                if (($row['documentId'] ?? null) !== null) {
                    $existing[self::refKey((string) $row['documentId'])] = true;
                }
            }
        }

        return $existing;
    }

    /**
     * Filter morph links in batches. The morph owner (`left`) is not checked:
     * morphColumn rows come from the owner table itself, and morph join-table
     * owners are FK-backed. Only dynamic morph targets (`right`) need existence
     * checks, and those have no DB-level FK.
     *
     * @param list<LinkData> $links
     *
     * @return \Generator<int, LinkData>
     */
    private function filterMorphLinksByTargetExistence(array $links): \Generator
    {
        $refsByType = [];

        foreach ($links as $link) {
            $type = $link['right']['type'] ?? null;
            $ref = $link['right']['ref'] ?? null;

            if ($ref === null || $type === null) {
                $this->onOrphanedLink?->__invoke($link);
            } else {
                $refsByType[(string) $type][] = $ref;
            }
        }

        $existingByType = [];

        foreach ($refsByType as $type => $refs) {
            $existingByType[$type] = $this->loadExistingRefs((string) $type, $refs);
        }

        foreach ($links as $link) {
            $type = $link['right']['type'] ?? null;
            $ref = $link['right']['ref'] ?? null;

            if ($ref === null || $type === null) {
                // already reported above
            } elseif (isset($existingByType[(string) $type][self::refKey($ref)])) {
                yield $link;
            } else {
                $this->onOrphanedLink?->__invoke($link);
            }
        }
    }

    /** @return \Generator<int, LinkData> */
    public function generateAllForAttribute(string $uid, string $fieldName): \Generator
    {
        $metadata = $this->metadata($uid);

        $attributes = self::filterValidRelationalAttributes($metadata['attributes']);

        if (!array_key_exists($fieldName, $attributes)) {
            throw new \RuntimeException("{$fieldName} is not a valid relational attribute name");
        }

        $attribute = $attributes[$fieldName];

        $kind = self::getLinkKind($attribute, $uid);
        $relation = (string) ($attribute['relation'] ?? '');
        $target = (string) ($attribute['target'] ?? '');
        $connection = $this->connection();

        // The relation is stored in the same table
        // TODO: handle manyToOne joinColumn
        if (!empty($attribute['joinColumn'])) {
            $joinColumnName = (string) $attribute['joinColumn']['name'];
            $referencedColumn = (string) ($attribute['joinColumn']['referencedColumn'] ?? 'id');
            $ownerTable = $this->addSchema((string) $metadata['tableName']);
            $targetTable = $this->addSchema($this->getMetadataTableName($target));

            // Use EXISTS (not JOIN): joining on non-unique referencedColumn (e.g. i18n
            // document_id) multiplies owner rows by matching target locales.
            $targetExistsSubquery = sprintf(
                'SELECT 1 FROM %s AS %s WHERE %s = %s',
                $this->q($targetTable),
                $this->q('target'),
                $this->q("target.{$referencedColumn}"),
                $this->q("owner.{$joinColumnName}")
            );

            $sql = sprintf(
                'SELECT %s, %s AS %s%s FROM %s AS %s WHERE %s IS NOT NULL%s',
                $this->q('owner.id'),
                $this->q("owner.{$joinColumnName}"),
                $this->q($joinColumnName),
                $this->onOrphanedLink !== null ? sprintf(', (EXISTS (%s)) AS %s', $targetExistsSubquery, $this->q(self::TARGET_EXISTS_ALIAS)) : '',
                $this->q($ownerTable),
                $this->q('owner'),
                $this->q("owner.{$joinColumnName}"),
                $this->onOrphanedLink === null ? " AND EXISTS ({$targetExistsSubquery})" : ''
            );

            // TODO: stream the query to improve performances
            $entries = $connection->fetchAllAssociative($sql);

            foreach ($entries as $entry) {
                $ref = self::toRef($entry[$joinColumnName] ?? null, $referencedColumn === 'id');
                $link = [
                    'kind' => $kind,
                    'relation' => $relation,
                    'left' => ['type' => $uid, 'ref' => self::toRef($entry['id']), 'field' => $fieldName],
                    'right' => ['type' => $target, 'ref' => $ref],
                ];

                // EXISTS yields 0/1 (or boolean); treat falsy as orphaned.
                if ($this->onOrphanedLink !== null && !self::truthy($entry[self::TARGET_EXISTS_ALIAS] ?? null)) {
                    ($this->onOrphanedLink)($link);
                } else {
                    yield $link;
                }
            }
        }

        // The relation uses a join table
        if (!empty($attribute['joinTable'])) {
            $joinTable = $attribute['joinTable'];
            $name = (string) $joinTable['name'];
            $joinColumn = $joinTable['joinColumn'] ?? [];
            $inverseJoinColumn = $joinTable['inverseJoinColumn'] ?? [];
            $orderColumnName = $joinTable['orderColumnName'] ?? null;
            $morphColumn = $joinTable['morphColumn'] ?? null;
            $inverseOrderColumnName = $joinTable['inverseOrderColumnName'] ?? null;

            if ($kind === 'relation.basic' || $kind === 'relation.circular') {
                $columns = [
                    'left' => ['ref' => (string) $joinColumn['name'], 'order' => is_string($orderColumnName) && $orderColumnName !== '' ? $orderColumnName : null],
                    'right' => ['ref' => (string) $inverseJoinColumn['name'], 'order' => is_string($inverseOrderColumnName) && $inverseOrderColumnName !== '' ? $inverseOrderColumnName : null],
                ];

                $joinTableName = $this->addSchema($name);
                $leftTable = $this->addSchema((string) $metadata['tableName']);
                $rightTable = $this->addSchema($this->getMetadataTableName($target));
                $leftReferencedColumn = 'left.' . ($joinColumn['referencedColumn'] ?? 'id');
                $rightReferencedColumn = 'right.' . ($inverseJoinColumn['referencedColumn'] ?? 'id');

                $validColumns = array_values(array_filter(
                    [$columns['left']['ref'], $columns['left']['order'], $columns['right']['ref'], $columns['right']['order']],
                    static fn (?string $column): bool => $column !== null
                ));

                $selects = array_map(fn (string $column): string => $this->q("join.{$column}") . ' AS ' . $this->q($column), $validColumns);
                if ($this->onOrphanedLink !== null) {
                    $selects[] = $this->q($leftReferencedColumn) . ' AS ' . $this->q(self::LEFT_EXISTS_ALIAS);
                    $selects[] = $this->q($rightReferencedColumn) . ' AS ' . $this->q(self::RIGHT_EXISTS_ALIAS);
                }

                $joinType = $this->onOrphanedLink !== null ? 'LEFT JOIN' : 'INNER JOIN';
                $sql = sprintf(
                    'SELECT %s FROM %s AS %s %s %s AS %s ON %s = %s %s %s AS %s ON %s = %s',
                    implode(', ', $selects),
                    $this->q($joinTableName),
                    $this->q('join'),
                    $joinType,
                    $this->q($leftTable),
                    $this->q('left'),
                    $this->q('join.' . $joinColumn['name']),
                    $this->q($leftReferencedColumn),
                    $joinType,
                    $this->q($rightTable),
                    $this->q('right'),
                    $this->q('join.' . $inverseJoinColumn['name']),
                    $this->q($rightReferencedColumn)
                );

                $entries = $connection->fetchAllAssociative($sql);

                foreach ($entries as $entry) {
                    $linkLeft = ['type' => $uid, 'field' => $fieldName];
                    $linkRight = ['type' => $target];
                    if (isset($attribute['inversedBy'])) {
                        $linkRight['field'] = $attribute['inversedBy'];
                    }

                    $linkLeft['ref'] = self::toRef($entry[$columns['left']['ref']] ?? null);
                    $linkRight['ref'] = self::toRef($entry[$columns['right']['ref']] ?? null);

                    if ($columns['left']['order'] !== null) {
                        $linkLeft['pos'] = self::toNumber($entry[$columns['left']['order']] ?? null);
                    }

                    if ($columns['right']['order'] !== null) {
                        $linkRight['pos'] = self::toNumber($entry[$columns['right']['order']] ?? null);
                    }

                    $link = [
                        'kind' => $kind,
                        'relation' => $relation,
                        'left' => self::orderLeft($linkLeft),
                        'right' => self::orderRight($linkRight),
                    ];

                    if ($this->onOrphanedLink !== null && (($entry[self::LEFT_EXISTS_ALIAS] ?? null) === null || ($entry[self::RIGHT_EXISTS_ALIAS] ?? null) === null)) {
                        ($this->onOrphanedLink)($link);
                    } else {
                        yield $link;
                    }
                }
            }

            if ($kind === 'relation.morph' && is_array($morphColumn)) {
                $columns = [
                    'left' => ['ref' => (string) $joinColumn['name']],
                    'right' => [
                        'ref' => (string) $morphColumn['idColumn']['name'],
                        'type' => (string) $morphColumn['typeColumn']['name'],
                        'field' => 'field',
                        'order' => 'order',
                    ],
                ];

                $validColumns = [$columns['left']['ref'], $columns['right']['ref'], $columns['right']['type'], $columns['right']['field'], $columns['right']['order']];

                $sql = sprintf(
                    'SELECT %s FROM %s',
                    implode(', ', array_map(fn (string $column): string => $this->q($column), $validColumns)),
                    $this->q($this->addSchema($name))
                );

                // TODO: stream the query to improve performances
                $entries = $connection->fetchAllAssociative($sql);
                $morphLinks = [];

                foreach ($entries as $entry) {
                    $left = ['type' => $uid, 'field' => $fieldName, 'ref' => self::toRef($entry[$columns['left']['ref']] ?? null)];
                    $right = [
                        'ref' => self::toRef($entry[$columns['right']['ref']] ?? null),
                        'pos' => self::toNumber($entry[$columns['right']['order']] ?? null),
                        'type' => $entry[$columns['right']['type']] ?? null,
                        'field' => $entry[$columns['right']['field']] ?? null,
                    ];

                    $morphLinks[] = [
                        'kind' => $kind,
                        'relation' => $relation,
                        'left' => self::orderLeft($left),
                        'right' => $right,
                    ];
                }

                yield from $this->filterMorphLinksByTargetExistence($morphLinks);
            }
        }

        if (!empty($attribute['morphColumn'])) {
            $typeColumn = (string) $attribute['morphColumn']['typeColumn']['name'];
            $idColumn = (string) $attribute['morphColumn']['idColumn']['name'];

            $sql = sprintf(
                'SELECT %s, %s, %s FROM %s WHERE %s IS NOT NULL AND %s IS NOT NULL',
                $this->q('id'),
                $this->q($typeColumn),
                $this->q($idColumn),
                $this->q($this->addSchema((string) $metadata['tableName'])),
                $this->q($typeColumn),
                $this->q($idColumn)
            );

            $entries = $connection->fetchAllAssociative($sql);
            $morphLinks = array_map(static fn (array $entry): array => [
                'kind' => $kind,
                'relation' => $relation,
                'left' => ['type' => $uid, 'ref' => self::toRef($entry['id']), 'field' => $fieldName],
                'right' => ['type' => $entry[$typeColumn], 'ref' => self::toRef($entry[$idColumn])],
            ], $entries);

            yield from $this->filterMorphLinksByTargetExistence($morphLinks);
        }
    }

    /**
     * @param array<string, mixed> $left
     *
     * @return array<string, mixed> upstream's key order: type, field, ref, pos
     */
    private static function orderLeft(array $left): array
    {
        $out = [];
        foreach (['type', 'field', 'ref', 'pos'] as $key) {
            if (array_key_exists($key, $left)) {
                $out[$key] = $left[$key];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed> upstream's key order: type, field, ref, pos
     */
    private static function orderRight(array $right): array
    {
        return self::orderLeft($right);
    }

    private static function toNumber(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_numeric($value) ? $value + 0 : null;
    }

    private static function truthy(mixed $value): bool
    {
        return !in_array($value, [null, false, 0, '0', '', 'f', 'false'], true);
    }

    /** @return \Generator<int, LinkData> */
    public function generateAll(string $uid): \Generator
    {
        $metadata = $this->metadata($uid);

        $attributes = self::filterValidRelationalAttributes($metadata['attributes']);

        foreach (array_keys($attributes) as $fieldName) {
            yield from $this->generateAllForAttribute($uid, (string) $fieldName);
        }
    }

    /** @param LinkData|array<string, mixed> $link */
    public function insert(array $link): void
    {
        $kind = $link['kind'] ?? null;
        $left = $link['left'];
        $right = $link['right'];

        $metadata = $this->metadata((string) $left['type']);
        $attribute = $metadata['attributes'][$left['field']] ?? null;
        $connection = $this->connection();

        $payload = [];

        /*
         * This _should_ only happen for attributes that are added dynamically e.g. review-workflow stages
         * and a user is importing EE data into a CE project.
         */
        if ($attribute === null) {
            return;
        }

        if (($attribute['type'] ?? null) !== 'relation') {
            throw new \RuntimeException("Attribute {$left['field']} is not a relation");
        }

        if (!empty($attribute['joinColumn'])) {
            $joinColumnName = (string) $attribute['joinColumn']['name'];

            // Note: this addSchema may not be necessary, but is added for safety
            $connection->executeStatement(
                sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $this->q($this->addSchema((string) $metadata['tableName'])), $this->q($joinColumnName), $this->q('id')),
                [$right['ref'] ?? null, $left['ref'] ?? null]
            );
        }

        if (!empty($attribute['joinTable'])) {
            $joinTable = $attribute['joinTable'];

            if (!empty($joinTable['joinColumn'])) {
                $payload[$joinTable['joinColumn']['name']] = $left['ref'] ?? null;
            }

            if ($kind === 'relation.basic' || $kind === 'relation.circular') {
                if (!empty($joinTable['inverseJoinColumn'])) {
                    $payload[$joinTable['inverseJoinColumn']['name']] = $right['ref'] ?? null;
                }
            }

            if ($kind === 'relation.morph' && !empty($joinTable['morphColumn'])) {
                $idColumn = $joinTable['morphColumn']['idColumn'] ?? null;
                $typeColumn = $joinTable['morphColumn']['typeColumn'] ?? null;

                if ($idColumn) {
                    $payload[$idColumn['name']] = $right['ref'] ?? null;
                }

                if ($typeColumn) {
                    $payload[$typeColumn['name']] = $right['type'] ?? null;
                }

                $payload['order'] = $right['pos'] ?? null;
                $payload['field'] = $right['field'] ?? null;
            }

            if (!empty($joinTable['orderColumnName'])) {
                $payload[$joinTable['orderColumnName']] = $left['pos'] ?? null;
            }

            if (!empty($joinTable['inverseOrderColumnName'])) {
                $payload[$joinTable['inverseOrderColumnName']] = $right['pos'] ?? null;
            }

            $columns = array_keys($payload);
            $connection->executeStatement(
                sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $this->q($this->addSchema((string) $joinTable['name'])),
                    implode(', ', array_map(fn (string|int $c): string => $this->q((string) $c), $columns)),
                    implode(', ', array_fill(0, count($columns), '?'))
                ),
                array_values($payload)
            );
        }

        if (!empty($attribute['morphColumn'])) {
            $morphColumn = $attribute['morphColumn'];

            $connection->executeStatement(
                sprintf(
                    'UPDATE %s SET %s = ?, %s = ? WHERE %s = ?',
                    $this->q($this->addSchema((string) $metadata['tableName'])),
                    $this->q((string) $morphColumn['idColumn']['name']),
                    $this->q((string) $morphColumn['typeColumn']['name']),
                    $this->q('id')
                ),
                [$right['ref'] ?? null, $right['type'] ?? null, $left['ref'] ?? null]
            );
        }
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, array<string, mixed>>
     */
    public static function filterValidRelationalAttributes(array $attributes): array
    {
        $isOwner = static fn (array $attribute): bool => !empty($attribute['owner']) || (empty($attribute['mappedBy']) && empty($attribute['morphBy']));

        $isComponentLike = static fn (array $attribute): bool => isset($attribute['joinTable']['name']) && str_ends_with((string) $attribute['joinTable']['name'], '_cmps');

        $out = [];
        foreach ($attributes as $key => $attribute) {
            if (is_array($attribute) && ($attribute['type'] ?? null) === 'relation' && $isOwner($attribute) && !$isComponentLike($attribute)) {
                $out[(string) $key] = $attribute;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $attribute */
    private static function getLinkKind(array $attribute, string $uid): string
    {
        if (str_starts_with((string) ($attribute['relation'] ?? ''), 'morph')) {
            return 'relation.morph';
        }

        if (($attribute['target'] ?? null) === $uid) {
            return 'relation.circular';
        }

        return 'relation.basic';
    }
}
