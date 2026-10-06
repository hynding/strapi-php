<?php

declare(strict_types=1);

namespace Strapi\Utils;

/** Port of packages/core/utils/src/content-api-constants.ts. */
final class ContentApiConstants
{
    /** Param keys shared by the Content API (query) and the document service. */
    public const SHARED_QUERY_PARAM_KEYS = [
        'filters',
        'sort',
        'fields',
        'populate',
        'status',
        'locale',
        'page',
        'pageSize',
        'start',
        'limit',
        '_q',
        'publicationFilter',
        'hasPublishedVersion',
    ];

    /** Core query param keys allowed by the Content API (validate/sanitize query with strictParams). */
    public const ALLOWED_QUERY_PARAM_KEYS = [
        ...self::SHARED_QUERY_PARAM_KEYS,
        'pagination',
        'count',
        'ordering',
    ];

    /** Root-level body.data keys reserved for core. */
    public const RESERVED_INPUT_PARAM_KEYS = [ContentTypes::ID_ATTRIBUTE, ContentTypes::DOC_ID_ATTRIBUTE];
}
