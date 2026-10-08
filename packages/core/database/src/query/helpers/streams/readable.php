<?php

declare(strict_types=1);

namespace Strapi\Database\Query\Helpers\Streams;

use Strapi\Database\Database;
use Strapi\Database\Query\Helpers\Populate\Apply;
use Strapi\Database\Query\Helpers\Transform;
use Strapi\Database\Query\QueryBuilder;
use Strapi\Database\Query\SqlBuilder;

/**
 * Port of packages/core/database/src/query/helpers/streams/readable.ts (`ReadableStrapiQuery`).
 *
 * A Node object-mode Readable becomes a PHP iterator: iterating it runs the query in batches of
 * `batchSize` rows (offset/limit windows within the query's own offset and limit), applies the
 * populate and maps the rows, and yields them one by one. `read($size)` is `_read(size)`: one batch,
 * or `null` once the stream has ended.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class Readable implements \IteratorAggregate
{
    /** Original offset value */
    public readonly int $offset;

    /** Max amount of entities to fetch (null: no limit) */
    public readonly ?int $limit;

    /** Total amount of entities fetched */
    public int $fetched = 0;

    private ?SqlBuilder $query = null;

    private bool $ended = false;

    public function __construct(
        private readonly QueryBuilder $qb,
        private readonly Database $db,
        private readonly string $uid,
        private readonly bool $mapResults = true,
        private readonly int $batchSize = 500,
    ) {
        // Extract offset & limit from the query-builder's state
        $offset = $qb->state['offset'] ?? null;
        $limit = $qb->state['limit'] ?? null;

        $this->offset = is_int($offset) ? $offset : 0;
        $this->limit = is_int($limit) ? $limit : null;
    }

    public function getIterator(): \Generator
    {
        while (($results = $this->read($this->batchSize)) !== null) {
            foreach ($results as $result) {
                yield $result;
            }
        }
    }

    /**
     * Reads the next batch. NOTE: "size" is the number of entities to read from the database.
     *
     * @return list<array<string, mixed>>|null null when the stream has ended
     */
    public function read(int $size): ?array
    {
        if ($this->ended) {
            return null;
        }

        // Original query
        $query = $this->query ??= $this->qb->getSqlQuery();

        // Remove the original offset & limit properties from the query
        $query->clear('limit')->clear('offset');

        // Define the maximum read size based on the limit and the requested size
        $maxReadSize = $this->limit === null ? $size : min($size, $this->limit);

        // Compute the limit for the next query: only the remaining entities when reading
        // `maxReadSize` would fetch too many (> limit)
        $limit = $this->limit !== null && $this->fetched + $maxReadSize > $this->limit
            ? $this->limit - $this->fetched
            : $maxReadSize;

        // Nothing left to read (limit === fetched): end the stream without querying
        if ($limit <= 0) {
            $this->ended = true;

            return null;
        }

        // Compute the offset (base offset + number of entities already fetched)
        $query->offset($this->offset + $this->fetched)->limit($limit);

        $results = $query->run();
        $results = is_array($results) ? array_values($results) : [];

        // Applies the populate if needed
        $populate = $this->qb->state['populate'] ?? null;
        if (is_array($populate) && $results !== []) {
            Apply::applyPopulate($results, $populate, $this->qb, $this->uid);
        }

        // Map results if asked to
        if ($this->mapResults) {
            $results = Transform::fromRow($this->db->metadata->get($this->uid), $results) ?? [];
        }

        $count = count($results);

        // Update the amount of fetched entities
        $this->fetched += $count;

        // If the amount of fetched entities is smaller than the maximum read size, close the stream
        if ($this->fetched === $this->limit || $count < $this->batchSize) {
            $this->ended = true;
        }

        /** @var list<array<string, mixed>> $results */
        return $count === 0 && $this->ended ? null : $results;
    }
}
