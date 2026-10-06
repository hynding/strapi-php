<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform\Relations\Utils;

use Strapi\Utils\Relations;

/** Port of transform/relations/utils/xto-one.ts. */
final class XtoOne
{
    /**
     * "Relates to one" fields hold a single entry. If a caller passes more than one, keep the last.
     * Runs on the user-provided payload only.
     *
     * @param array<string, mixed> $attribute
     */
    public static function normalizeXToOneRelationValue(array $attribute, mixed $value): mixed
    {
        if (($attribute['type'] ?? null) !== 'media' && !Relations::isAnyToOne($attribute)) {
            return $value;
        }
        if (($attribute['type'] ?? null) === 'media' && ($attribute['multiple'] ?? false)) {
            return $value;
        }

        if ($value === null) {
            return $value;
        }

        if (is_array($value) && array_is_list($value)) {
            return count($value) > 1 ? [$value[count($value) - 1]] : $value;
        }

        if (is_array($value)) {
            if (isset($value['set']) && is_array($value['set']) && array_is_list($value['set']) && count($value['set']) > 1) {
                return [...$value, 'set' => [$value['set'][count($value['set']) - 1]]];
            }
        }

        return $value;
    }
}
