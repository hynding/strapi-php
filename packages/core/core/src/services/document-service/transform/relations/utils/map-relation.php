<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform\Relations\Utils;

use Strapi\Utils\TraverseEntity;

/**
 * Port of transform/relations/utils/map-relation.ts: `mapRelation(callback, relation)` traverses
 * every form a relation may take (shorthand, longhand, lists, `{ set, connect, disconnect }`) and
 * `traverseEntityRelations` visits only relation (and optionally media) attributes.
 */
final class MapRelation
{
    private static function isNumeric(mixed $value): bool
    {
        if (is_array($value)) {
            return false; // Handle [1, 'docId'] case
        }
        if (is_int($value)) {
            return true;
        }
        if (is_string($value)) {
            // parseInt(value, 10): leading digits
            return preg_match('/^\s*[+-]?\d/', $value) === 1;
        }

        return is_float($value);
    }

    private static function toArray(mixed $value): mixed
    {
        // Keep value as it is if it's a nullish value
        if ($value === null) {
            return $value;
        }
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }

        return [$value];
    }

    /**
     * For consistency and ease of use, the response will always be an object with the following
     * shape: `{ set: [{...}], connect: [{...}], disconnect: [{...}] }`.
     *
     * @param callable(mixed): mixed $callback
     */
    public static function mapRelation(callable $callback, mixed $rel, bool $isRecursive = false): mixed
    {
        $relation = $rel;

        $wrapInSet = static function (mixed $value) use ($isRecursive): mixed {
            // Ignore wrapping if it's a recursive call
            if ($isRecursive) {
                return $value;
            }

            return ['set' => self::toArray($value)];
        };

        // undefined | null
        if ($relation === null) {
            return $callback($relation);
        }

        // LongHand[] | ShortHand[]
        if (is_array($relation) && array_is_list($relation)) {
            $result = [];
            foreach ($relation as $r) {
                $mapped = self::mapRelation($callback, $r, true);
                // flat().filter(Boolean)
                if (is_array($mapped) && array_is_list($mapped)) {
                    foreach ($mapped as $item) {
                        if ($item !== null && $item !== false) {
                            $result[] = $item;
                        }
                    }
                } elseif ($mapped !== null && $mapped !== false) {
                    $result[] = $mapped;
                }
            }

            return $wrapInSet($result);
        }

        // LongHand
        if (is_array($relation)) {
            // { id: 1 } || { documentId: 1 }
            if (array_key_exists('id', $relation) || array_key_exists('documentId', $relation)) {
                return $wrapInSet($callback($relation));
            }

            // If not connecting anything, return default visitor
            if (!isset($relation['set']) && !isset($relation['disconnect']) && !isset($relation['connect'])) {
                return $callback($relation);
            }

            // { set }
            if (isset($relation['set'])) {
                $set = self::mapRelation($callback, $relation['set'], true);
                $relation = [...$relation, 'set' => self::toArray($set)];
            }

            // { disconnect }
            if (isset($relation['disconnect'])) {
                $disconnect = self::mapRelation($callback, $relation['disconnect'], true);
                $relation = [...$relation, 'disconnect' => self::toArray($disconnect)];
            }

            // { connect }
            if (isset($relation['connect'])) {
                $connect = self::mapRelation($callback, $relation['connect'], true);
                $relation = [...$relation, 'connect' => self::toArray($connect)];
            }

            return $relation;
        }

        // ShortHand
        if (self::isNumeric($relation)) {
            return $wrapInSet($callback(['id' => $relation]));
        }

        if (is_string($relation)) {
            return $wrapInSet($callback(['documentId' => $relation]));
        }

        // Anything else
        return $callback($relation);
    }

    /**
     * Same as `traverseEntity` but only for relations and, when requested, media.
     *
     * @param callable(\Strapi\Utils\Traverse\VisitorOptions, \Strapi\Utils\Traverse\VisitorUtils): void $visitor
     * @param array{schema: mixed, getModel: callable, includeMedia?: bool} $options
     */
    public static function traverseEntityRelations(callable $visitor, array $options, mixed $data): mixed
    {
        $includeMedia = $options['includeMedia'] ?? false;
        unset($options['includeMedia']);

        return TraverseEntity::traverse(static function (\Strapi\Utils\Traverse\VisitorOptions $opts, \Strapi\Utils\Traverse\VisitorUtils $utils) use ($visitor, $includeMedia): void {
            $attribute = $opts->attribute;

            if ($attribute === null) {
                return;
            }

            $type = $attribute['type'] ?? null;
            if ($type !== 'relation' && !($includeMedia && $type === 'media')) {
                return;
            }

            if ($type === 'relation') {
                // TODO: Handle join columns
                if (($attribute['useJoinTable'] ?? null) === false) {
                    return;
                }

                // morphToOne uses morphColumn (inline columns on the entity), handled directly in processData
                if (($attribute['relation'] ?? null) === 'morphToOne') {
                    return;
                }
            }

            $visitor($opts, $utils);
        }, $options, $data);
    }
}
