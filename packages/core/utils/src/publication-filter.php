<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Errors\ValidationError;

/**
 * Port of the parsing/validation half of packages/core/utils/src/publication-filter.ts.
 * `buildPublicationFilterWhere` builds knex subqueries and belongs to the database package.
 */
final class PublicationFilter
{
    public const ALLOWED = [
        'never-published',
        'has-published-version',
        'modified',
        'unmodified',
        'never-published-document',
        'has-published-version-document',
        'published-without-draft',
        'published-with-draft',
    ];

    public static function parsePublicationFilter(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && in_array($value, self::ALLOWED, true)) {
            return $value;
        }

        throw new ValidationError("Invalid value for 'publicationFilter'. Expected one of: " . implode(', ', self::ALLOWED) . '.');
    }

    /** Validates a `publicationFilter` query value, attaching `details.source` / `details.param`. */
    public static function validatePublicationFilterQueryParam(mixed $value): void
    {
        if ($value === null) {
            return;
        }

        try {
            self::parsePublicationFilter($value);
        } catch (ValidationError $e) {
            $e->details = [...$e->details, 'source' => 'query', 'param' => 'publicationFilter'];
            throw $e;
        }
    }

    /**
     * Port of has-published-version-param.ts: parses the deprecated `hasPublishedVersion` param.
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

    public static function hasPublishedVersionBooleanToPublicationFilterMode(bool $value): string
    {
        return $value ? 'has-published-version-document' : 'never-published-document';
    }
}
