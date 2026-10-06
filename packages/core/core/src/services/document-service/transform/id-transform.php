<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform;

use Strapi\Core\Strapi;

/** Port of transform/id-transform.ts: transform input of a query to map document ids to entity ids. */
final class IdTransform
{
    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function transformParamsDocumentId(Strapi $strapi, string $uid, array $query): array
    {
        // Transform relational documentIds to entity ids
        $data = $query['data'] ?? null;
        if (is_array($data)) {
            $data = Data::transformData($strapi, $data, ['locale' => $query['locale'] ?? null, 'status' => $query['status'] ?? null, 'uid' => $uid]);
        }

        // Make sure documentId is always present in the response
        $fields = $query['fields'] ?? null;
        if ($fields !== null) {
            $fields = Fields::transformFields($fields);
        }

        $populate = $query['populate'] ?? null;
        if ($populate !== null) {
            $populate = Populate::transformPopulate($strapi, $populate, ['uid' => $uid]);
        }

        $out = $query;
        if (array_key_exists('data', $query)) {
            $out['data'] = $data;
        }
        if (array_key_exists('fields', $query)) {
            $out['fields'] = $fields;
        }
        if (array_key_exists('populate', $query)) {
            $out['populate'] = $populate;
        }

        return $out;
    }
}
