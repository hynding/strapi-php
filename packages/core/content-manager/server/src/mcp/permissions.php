<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp;

use Strapi\ContentManager\Utils\Utils as CmUtils;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of server/src/mcp/permissions.ts. Permitted-field sets are PHP sets (`array<string, true>`);
 * `null` means every field is permitted.
 */
final class Permissions
{
    /** True when the content type has the i18n `localized` plugin option enabled. */
    public static function isContentTypeLocalized(Strapi $strapi, string $uid): bool
    {
        $ct = $strapi->contentTypes()[$uid] ?? null;
        if ($ct === null) {
            return false;
        }
        $pluginOptions = $ct instanceof Schema ? $ct->pluginOptions : ($ct['pluginOptions'] ?? []);

        return ($pluginOptions['i18n']['localized'] ?? null) === true;
    }

    /** @param list<string> $allowedLocales */
    private static function localeDefaultDescription(?string $defaultLocale, array $allowedLocales): string
    {
        if ($defaultLocale !== null && in_array($defaultLocale, $allowedLocales, true)) {
            return "Defaults to \"{$defaultLocale}\".";
        }

        return 'Defaults to the default locale.';
    }

    /**
     * The base locale schema of a derived tool input: an enum of the installed locale codes (with
     * the default locale as default) when i18n is installed, an optional string otherwise.
     *
     * @param list<string>|null $localeCodes
     */
    public static function buildLocaleSchema(?array $localeCodes, ?string $defaultLocale): ZodType
    {
        if ($localeCodes !== null && $localeCodes !== []) {
            $schema = z::enum($localeCodes)->optional();
            if ($defaultLocale !== null && in_array($defaultLocale, $localeCodes, true)) {
                $schema = $schema->default($defaultLocale);
            }

            return $schema->describe('Locale code. Available: ' . implode(', ', $localeCodes) . '. ' . self::localeDefaultDescription($defaultLocale, $localeCodes));
        }

        return z::string()->optional()->describe('Locale code (e.g. "en", "fr"). Defaults to the default locale.');
    }

    /**
     * Narrows the base locale schema to the locales the session may use for `action` on `uid`:
     * unchanged without i18n or when every locale is permitted, a "not localized" string when the
     * content type is not localized, `z.never().optional()` when no locale is permitted.
     *
     * @param array<string, mixed> $context
     * @param list<string>|null $localeCodes
     */
    public static function resolvePermittedLocaleSchema(Strapi $strapi, array $context, string $action, string $uid, ?array $localeCodes, ?string $defaultLocale, ZodType $baseLocaleSchema): ZodType
    {
        if ($localeCodes === null) {
            return $baseLocaleSchema;
        }

        if (!self::isContentTypeLocalized($strapi, $uid)) {
            return z::string()->optional()->describe('This content type is not localized. Locale is ignored.');
        }

        $permissionChecker = Handlers\CollectionHandlers::checker($strapi, $context, $uid);
        $permitted = self::getPermittedLocales($permissionChecker, $action, $localeCodes);
        if ($permitted === null) {
            return $baseLocaleSchema;
        }
        if ($permitted === []) {
            return z::never()->optional()->describe('No locale access for this action.');
        }

        $schema = z::enum($permitted)->optional();
        if ($defaultLocale !== null && in_array($defaultLocale, $permitted, true)) {
            $schema = $schema->default($defaultLocale);
        }

        return $schema->describe('Locale code. Permitted: ' . implode(', ', $permitted) . '. ' . self::localeDefaultDescription($defaultLocale, $permitted));
    }

    /**
     * The leaf field paths of a component, as CASL rules name them (`SEO.title`, `SEO.og.image`):
     * the admin RBAC decomposes component attributes into nested paths and removes the parent key.
     *
     * @param array<string, true> $visited
     * @return list<string>
     */
    public static function getComponentLeafPaths(Strapi $strapi, string $componentUid, string $prefix, array $visited = []): array
    {
        if (isset($visited[$componentUid])) {
            return [$prefix];
        }

        $component = $strapi->components()[$componentUid] ?? null;
        if ($component === null) {
            return [$prefix];
        }

        $visited[$componentUid] = true;
        $paths = [];
        $attributes = $component instanceof Schema ? $component->attributes : ($component['attributes'] ?? []);

        foreach ($attributes as $key => $attr) {
            if ($key === 'id') {
                // skip system id field — it is not a user-facing permission path
                continue;
            }
            $fieldPath = "{$prefix}.{$key}";

            if (($attr['type'] ?? null) === 'component' && is_string($attr['component'] ?? null)) {
                array_push($paths, ...self::getComponentLeafPaths($strapi, $attr['component'], $fieldPath, $visited));
            } else {
                $paths[] = $fieldPath;
            }
        }

        return $paths !== [] ? $paths : [$prefix];
    }

    /**
     * The attribute keys the session may access for `action` on `uid` (components count when one
     * of their leaf paths is permitted); `null` when every field is permitted.
     *
     * @param array<string, array<string, mixed>> $attributes
     * @return array<string, true>|null
     */
    public static function getPermittedFields(Strapi $strapi, Ability $userAbility, string $action, string $uid, array $attributes): ?array
    {
        $permitted = [];
        foreach ($attributes as $key => $attr) {
            $key = (string) $key;
            $allowed = $userAbility->can($action, $uid, $key);

            if (!$allowed && ($attr['type'] ?? null) === 'component' && is_string($attr['component'] ?? null)) {
                foreach (self::getComponentLeafPaths($strapi, $attr['component'], $key) as $path) {
                    $allowed = $allowed || $userAbility->can($action, $uid, $path);
                }
            }

            if ($allowed) {
                $permitted[$key] = true;
            }
        }

        if (count($permitted) === count($attributes)) {
            return null;
        }

        return $permitted;
    }

    /**
     * The locale codes the session may access for `action`: `null` when all are permitted, `[]`
     * when none is.
     *
     * @param \Strapi\ContentManager\Services\PermissionChecker $permissionChecker
     * @param list<string> $localeCodes
     * @return list<string>|null
     */
    public static function getPermittedLocales(object $permissionChecker, string $action, array $localeCodes): ?array
    {
        $permitted = array_values(array_filter($localeCodes, static fn (string $code): bool => $permissionChecker->cannot($action, ['locale' => $code]) === false));

        return count($permitted) === count($localeCodes) ? null : $permitted;
    }
}
