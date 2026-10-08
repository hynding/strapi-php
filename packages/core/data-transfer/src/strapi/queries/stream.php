<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Queries;

use Strapi\Core\Strapi;

/**
 * Not an upstream file: `strapi.db.queryBuilder(uid).select('*').populate(...).stream()`, read in
 * pages (by id) so large tables are not loaded at once. Rows are mapped to attributes and
 * populated as the query builder does.
 */
final class Stream
{
    public const int BATCH_SIZE = 500;

    /**
     * @param array<string, mixed>|list<string>|null $populate
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function rows(Strapi $strapi, string $uid, array|null $populate = null, int $batchSize = self::BATCH_SIZE): \Generator
    {
        $offset = 0;

        while (true) {
            $qb = $strapi->db()->queryBuilder($uid)
                // Fetch all columns
                ->select('*')
                ->orderBy(['id' => 'asc'])
                ->limit($batchSize)
                ->offset($offset);

            if ($populate !== null && $populate !== []) {
                $qb->populate($populate);
            }

            $rows = $qb->execute();
            if (!is_array($rows) || $rows === []) {
                return;
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }

            if (count($rows) < $batchSize) {
                return;
            }

            $offset += $batchSize;
        }
    }
}
