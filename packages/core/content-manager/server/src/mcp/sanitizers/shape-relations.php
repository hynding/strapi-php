<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp\Sanitizers;

use Strapi\Core\Strapi;
use Strapi\Utils\TraverseEntity;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/**
 * Port of server/src/mcp/sanitizers/shape-relations.ts: reduces every relation of an MCP output
 * document to identity-only entries (`{ documentId, locale?, __type?, status? }`).
 *
 * @phpstan-type RelationIdentity array{documentId: string, locale?: string, __type?: string, status?: string}
 */
final class ShapeRelations
{
    /** Relations whose cardinality is "many" — maps to array output; includes manyWay and morph-many. */
    public const MANY_RELATION_TYPES = ['oneToMany', 'manyToMany', 'manyWay', 'morphToMany', 'morphMany'];

    /**
     * Canonical "is this relation a list" predicate for the MCP output boundary. Both the runtime
     * shaping and the registered output schemas MUST use it (the structuredContent is validated
     * against the schema). Broader than `relations.isAnyToMany`, which ignores manyWay and morphs.
     *
     * @param array<string, mixed> $attribute
     */
    public static function isManyRelationForMcp(array $attribute): bool
    {
        return in_array($attribute['relation'] ?? '', self::MANY_RELATION_TYPES, true);
    }

    /**
     * Extracts the identity fields of one relation entry; null when it has no documentId (e.g. a
     * bare `{ count }`).
     *
     * @return RelationIdentity|null
     */
    private static function pickIdentity(mixed $entry): ?array
    {
        if (!is_array($entry) || !is_string($entry['documentId'] ?? null)) {
            return null;
        }

        $identity = ['documentId' => $entry['documentId']];
        if (is_string($entry['locale'] ?? null)) {
            $identity['locale'] = $entry['locale'];
        }
        if (is_string($entry['__type'] ?? null)) {
            $identity['__type'] = $entry['__type'];
        }
        // Preserve the computed publish status on `localizations` entries (calculate, then strip).
        if (is_string($entry['status'] ?? null)) {
            $identity['status'] = $entry['status'];
        }

        return $identity;
    }

    /**
     * to-one → identity | null; to-many → list of identities (`[]` when empty).
     *
     * @param array<string, mixed> $attribute
     * @return RelationIdentity|list<RelationIdentity>|null
     */
    public static function reduceToIdentity(array $attribute, mixed $value): ?array
    {
        if (self::isManyRelationForMcp($attribute)) {
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter(array_map(self::pickIdentity(...), $value), static fn (?array $v): bool => $v !== null));
            }

            // defensive: { count: N } or null → empty array
            return [];
        }

        // to-one
        if ($value === null) {
            return null;
        }
        if (is_array($value) && array_is_list($value)) {
            // should not happen, but guard: treat first element
            return self::pickIdentity($value[0] ?? null);
        }

        return self::pickIdentity($value);
    }

    /**
     * Applies identity shaping to every relation field of a document, nested components, dynamic
     * zones and `localizations` included. `admin::user` relations are out of scope and kept as is.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function shapeRelationsForMcp(Strapi $strapi, string $uid, array $data): array
    {
        $visitor = static function (VisitorOptions $options, VisitorUtils $utils): void {
            $attribute = $options->attribute;
            if (!is_array($attribute) || ($attribute['type'] ?? null) !== 'relation') {
                return;
            }
            if (($attribute['target'] ?? null) === 'admin::user') {
                return;
            }
            $utils->set($options->key, self::reduceToIdentity($attribute, $options->value));
        };

        $result = TraverseEntity::traverse($visitor, [
            'schema' => $strapi->getModel($uid),
            'getModel' => static fn (string $modelUid): mixed => $strapi->getModel($modelUid),
        ], $data);

        return is_array($result) ? $result : $data;
    }
}
