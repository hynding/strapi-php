<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services;

/** Port of server/src/services/constants.ts. */
final class Constants
{
    public const array MODEL_TYPES = [
        'CONTENT_TYPE' => 'CONTENT_TYPE',
        'COMPONENT' => 'COMPONENT',
    ];

    public const array TYPE_KINDS = [
        'SINGLE_TYPE' => 'singleType',
        'COLLECTION_TYPE' => 'collectionType',
    ];

    public const array DEFAULT_TYPES = [
        // advanced types
        'media',

        // scalar types
        'string',
        'text',
        'richtext',
        'blocks',
        'json',
        'enumeration',
        'password',
        'email',
        'integer',
        'biginteger',
        'float',
        'decimal',
        'date',
        'time',
        'datetime',
        'timestamp',
        'boolean',

        'relation',
    ];

    public const array VALID_UID_TARGETS = ['string', 'text'];

    public const array CORE_UIDS = [
        'STRAPI_USER' => 'admin::user',
        'PREFIX' => 'strapi::',
    ];

    public const array PLUGINS_UIDS = [
        'UPLOAD_FILE' => 'plugin::upload.file',
    ];
}
