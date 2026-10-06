<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform;

/** Port of transform/fields.ts: ensure `documentId` is always selected. */
final class Fields
{
    public static function transformFields(mixed $fields): mixed
    {
        // If it's a string, and it doesn't contain documentId, should be an array
        if (is_string($fields)) {
            if ($fields === '*') {
                return $fields;
            }

            if ($fields === '') {
                return 'documentId';
            }

            if (!in_array('documentId', explode(',', $fields), true)) {
                return "{$fields},documentId";
            }

            return $fields;
        }

        // It's not an array, ignore it
        if (!is_array($fields) || !array_is_list($fields)) {
            return $fields;
        }

        // Ensure we are always selecting the documentId
        if (!in_array('documentId', $fields, true)) {
            $fields[] = 'documentId';
        }

        return $fields;
    }
}
