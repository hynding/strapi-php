<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Errors\ValidationError;

/** Port of packages/core/utils/src/has-published-version-param.ts. */
final class HasPublishedVersionParam
{
    /**
     * Parses the deprecated `hasPublishedVersion` query param (REST boolean or "true"/"false" strings).
     *
     * @deprecated Prefer `publicationFilter` with document-scoped modes.
     */
    public static function parseHasPublishedVersionQueryParam(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if ($value === true || $value === 'true') {
            return true;
        }

        if ($value === false || $value === 'false') {
            return false;
        }

        throw new ValidationError("Invalid value for 'hasPublishedVersion'. Expected boolean or 'true'/'false' string.");
    }

    /**
     * Maps legacy boolean to the document-scoped `publicationFilter` cohorts (same semantics as the old subquery).
     *
     * @return 'has-published-version-document'|'never-published-document'
     */
    public static function hasPublishedVersionBooleanToPublicationFilterMode(bool $value): string
    {
        return $value ? 'has-published-version-document' : 'never-published-document';
    }
}
