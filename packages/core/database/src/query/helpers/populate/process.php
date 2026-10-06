<?php

declare(strict_types=1);

namespace Strapi\Database\Query\Helpers\Populate;

use Strapi\Database\Query\QueryBuilder;
use Strapi\Database\Utils\Types;

/**
 * Port of packages/core/database/src/query/helpers/populate/process.ts: normalises the populate
 * param (`true`, `'*'`, `['a', 'b.c']`, `{ a: true, b: { populate: ... } }`) into a map and adds
 * the join columns / id to the select so populate can run after the fetch.
 */
final class Process
{
    /** @param array<string, mixed> $meta  @return array<string, mixed> */
    private static function getRootLevelPopulate(array $meta): array
    {
        $populate = [];
        foreach ($meta['attributes'] as $attributeName => $attribute) {
            if (($attribute['type'] ?? null) === 'relation') {
                $populate[$attributeName] = true;
            }
        }

        return $populate;
    }

    /** @return array<string, mixed>|null */
    public static function processPopulate(mixed $populate, QueryBuilder $qb, string $uid): ?array
    {
        $meta = $qb->db->metadata->get($uid);

        if ($populate === false || $populate === null) {
            return null;
        }

        $populateMap = [];

        if ($populate === true || $populate === '*') {
            $populateMap = self::getRootLevelPopulate($meta);
        } elseif (is_string($populate)) {
            $populate = [$populate];
        }

        if (is_array($populate) && array_is_list($populate)) {
            foreach ($populate as $key) {
                $parts = explode('.', (string) $key);
                $root = array_shift($parts);

                if ($parts !== []) {
                    $subPopulate = implode('.', $parts);
                    if (isset($populateMap[$root])) {
                        $populateValue = $populateMap[$root];
                        if ($populateValue === true) {
                            $populateMap[$root] = ['populate' => [$subPopulate]];
                        } else {
                            $populateValue['populate'] = [$subPopulate, ...(array) ($populateValue['populate'] ?? [])];
                            $populateMap[$root] = $populateValue;
                        }
                    } else {
                        $populateMap[$root] = ['populate' => [$subPopulate]];
                    }
                } else {
                    $populateMap[$root] = $populateMap[$root] ?? true;
                }
            }
        } elseif (is_array($populate)) {
            $populateMap = $populate;
        }

        if (!is_array($populateMap)) {
            throw new \InvalidArgumentException('Populate must be an object');
        }

        $finalPopulate = [];
        foreach ($populateMap as $key => $value) {
            $attribute = $meta['attributes'][$key] ?? null;

            if ($attribute === null || !Types::isRelation((string) ($attribute['type'] ?? ''))) {
                continue;
            }

            if ($value === false) {
                continue;
            }

            // Make sure to query the join column value if needed, so that we can apply the populate later on
            if (!empty($attribute['joinColumn'])) {
                $qb->addSelect($attribute['joinColumn']['name']);
            }

            // Make sure id is present for future populate queries
            if (isset($meta['attributes']['id'])) {
                $qb->addSelect('id');
            }

            $finalPopulate[$key] = $value;
        }

        return $finalPopulate;
    }
}
