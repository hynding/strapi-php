<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore;

use Strapi\Core\Strapi;

/**
 * Port of src/strapi/providers/local-destination/strategies/restore/resolve-link-ref.ts.
 */
final class ResolveLinkRef
{
    /**
     * joinColumn relations (e.g. i18n `localizations`) store `document_id` on the
     * inverse side. Export emits that value as a string ref; restore must not run it
     * through the numeric id mapper.
     *
     * @param array<string, mixed> $link
     */
    public static function isDocumentIdJoinColumnTarget(Strapi $strapi, array $link, string $side): bool
    {
        if ($side !== 'right') {
            return false;
        }

        $leftType = $link['left']['type'] ?? null;
        if (!is_string($leftType) || !$strapi->db()->metadata->has($leftType)) {
            return false;
        }

        $metadata = $strapi->db()->metadata->get($leftType);
        $attribute = $metadata['attributes'][$link['left']['field'] ?? ''] ?? null;

        if (!is_array($attribute) || ($attribute['type'] ?? null) !== 'relation') {
            return false;
        }

        if (empty($attribute['joinColumn'])) {
            return false;
        }

        return ($attribute['joinColumn']['referencedColumn'] ?? null) === 'document_id';
    }

    /**
     * @param array<string, mixed>                 $link
     * @param callable(string, int): (int|null)    $mapID
     */
    public static function resolveLinkRef(Strapi $strapi, array $link, string $side, callable $mapID): int|string|null
    {
        $type = (string) ($link[$side]['type'] ?? '');
        $ref = $link[$side]['ref'] ?? null;

        if (self::isDocumentIdJoinColumnTarget($strapi, $link, $side)) {
            return is_int($ref) || is_string($ref) ? $ref : null;
        }

        // Number(ref): numbers and numeric strings
        if (is_int($ref)) {
            $numericRef = $ref;
        } elseif (is_float($ref) && is_finite($ref)) {
            $numericRef = (int) $ref;
        } elseif (is_string($ref) && is_numeric(trim($ref))) {
            $numericRef = (int) trim($ref);
        } elseif ($ref === null || $ref === '' || $ref === false) {
            // Number(null) === 0, Number('') === 0
            $numericRef = 0;
        } elseif ($ref === true) {
            $numericRef = 1;
        } else {
            return null;
        }

        return $mapID($type, $numericRef);
    }
}
