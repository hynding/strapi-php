<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Homepage\Services;

use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\ContentManager\Services\Utils\Configuration\Attributes;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/** Port of server/src/homepage/services/homepage-query-utils.ts. */
final class HomepageQueryUtils
{
    public const string FALLBACK_MAIN_FIELD = 'documentId';

    /**
     * Removes invalid entries left in the fields array after permission sanitization.
     *
     * @return list<string>|null
     */
    public static function compactSanitizedFields(mixed $fields): ?array
    {
        if (!is_array($fields)) {
            return null;
        }

        return array_values(array_filter($fields, static fn (mixed $field): bool => is_string($field)));
    }

    /**
     * Resolves the main field used for homepage widgets, falling back when the user cannot read it.
     *
     * @param array{settings?: array{mainField?: string}}|null $configuration
     * @param PermissionChecker $permissionChecker `cannot('read', null, $field)` is all that is used
     */
    public static function resolveReadableMainField(Schema $contentType, ?array $configuration, object $permissionChecker): string
    {
        $candidateMainField = $configuration['settings']['mainField'] ?? Attributes::getDefaultMainField(ContentTypes::toArray($contentType));

        if ($permissionChecker->cannot('read', null, $candidateMainField)) {
            return self::FALLBACK_MAIN_FIELD;
        }

        return $candidateMainField;
    }

    /**
     * Builds the fields array requested before permission sanitization.
     *
     * @return list<string>
     */
    public static function buildHomepageQueryFields(Schema $contentType, string $mainField): array
    {
        $fields = [self::FALLBACK_MAIN_FIELD, 'updatedAt'];

        if (ContentTypes::hasDraftAndPublish($contentType)) {
            $fields[] = 'publishedAt';
        }

        if ($mainField !== self::FALLBACK_MAIN_FIELD && !in_array($mainField, $fields, true)) {
            $fields[] = $mainField;
        }

        if (!empty($contentType->pluginOptions['i18n']['localized'])) {
            $fields[] = 'locale';
        }

        return $fields;
    }

    /**
     * Picks a main field that is present in the sanitized fields selection.
     *
     * @param list<string>|null $sanitizedFields
     */
    public static function resolveTitleField(string $mainField, ?array $sanitizedFields): string
    {
        if ($sanitizedFields !== null && in_array($mainField, $sanitizedFields, true)) {
            return $mainField;
        }

        return self::FALLBACK_MAIN_FIELD;
    }
}
