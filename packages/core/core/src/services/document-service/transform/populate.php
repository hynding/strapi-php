<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform;

use Strapi\Core\Strapi;
use Strapi\Utils\Traverse\QueryPopulate;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/** Port of transform/populate.ts: add `documentId` to the `fields` of every populated relation. */
final class Populate
{
    /** @param array{uid: string} $opts */
    public static function transformPopulate(Strapi $strapi, mixed $data, array $opts): mixed
    {
        return QueryPopulate::traverse(
            static function (VisitorOptions $options, VisitorUtils $utils): void {
                $value = $options->value;
                if (!is_array($value) || array_is_list($value) || ($options->attribute['type'] ?? null) !== 'relation') {
                    return;
                }

                // If the attribute is a relation, look for fields in the value and apply the relevant transformation
                if (isset($value['fields']) && is_array($value['fields']) && array_is_list($value['fields'])) {
                    $value['fields'] = Fields::transformFields($value['fields']);
                }

                $utils->set($options->key, $value);
            },
            ['schema' => $strapi->getModel($opts['uid']), 'getModel' => static fn (string $uid) => $strapi->getModel($uid)],
            $data,
        );
    }
}
