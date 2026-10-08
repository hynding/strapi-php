<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Engine\Validation\Schemas;

use Strapi\DataTransfer\Utils\Json;

/**
 * Port of src/engine/validation/schemas/index.ts: `compareSchemas(a, b, strategy)`.
 *
 * @phpstan-import-type Diff from Json
 */
final class Schemas
{
    private const array OPTIONAL_CONTENT_TYPES = ['audit-log'];

    /** @param Diff $diff */
    private static function isAttributeIgnorable(array $diff): bool
    {
        return count($diff['path']) === 3
            // Root property must be attributes
            && $diff['path'][0] === 'attributes'
            // Need a valid string attribute name (paths are always strings here)
            // The diff must be on ignorable attribute properties
            && in_array($diff['path'][2], ['private', 'required', 'configurable', 'default'], true);
    }

    /**
     * exclude admin tables that are not transferable and are optionally available (such as audit
     * logs which are only available in EE)
     *
     * @param Diff $diff
     */
    private static function isOptionalAdminType(array $diff): bool
    {
        // added/deleted
        if (array_key_exists('value', $diff) && is_array($diff['value'])) {
            $name = $diff['value']['info']['singularName'] ?? null;

            return in_array($name, self::OPTIONAL_CONTENT_TYPES, true);
        }

        // modified
        if (array_key_exists('values', $diff) && is_array($diff['values'][0] ?? null)) {
            $name = $diff['values'][0]['info']['singularName'] ?? null;

            return in_array($name, self::OPTIONAL_CONTENT_TYPES, true);
        }

        return false;
    }

    /** @param Diff $diff */
    private static function isIgnorableStrict(array $diff): bool
    {
        return self::isAttributeIgnorable($diff) || self::isOptionalAdminType($diff);
    }

    /**
     * @param 'exact'|'strict'|string $strategy
     *
     * @return list<Diff>
     */
    public static function compareSchemas(mixed $a, mixed $b, string $strategy): array
    {
        $diffs = Json::diff($a, $b);

        return match ($strategy) {
            // No diffs
            'exact' => $diffs,
            // Strict: all content types must match except:
            // - the property within a content type is an ignorable one
            // - those that are (not transferrable and optionally available), for example EE features such as audit logs
            'strict' => array_values(array_filter($diffs, static fn (array $diff): bool => !self::isIgnorableStrict($diff))),
            default => throw new \TypeError('strategies[strategy] is not a function'),
        };
    }
}
