<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n;

use Strapi\Core\Strapi;
use Strapi\Database\Utils\Identifiers\Identifiers;
use Strapi\Plugin\I18n\Controllers\ValidateLocaleCreation;
use Strapi\Plugin\I18n\Models\AiLocalizationJob;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Types\Core\Context;
use Strapi\Types\Schema\Schema;

/**
 * Port of server/src/register.ts.
 *
 * - The plugin registers its {@see LocalizationProvider} on `strapi.localization` (strapi-php's
 *   core reads i18n through it).
 * - `strapi.server.router.use(path, mw)` has no counterpart in this port's router: the locale
 *   middleware is an admin API middleware (`server.api('admin').use()`) applied to the same paths
 *   (`/content-manager/collection-types/:model…`, `/content-manager/single-types/:model…`).
 * - Schemas are immutable: `extendContentTypes` replaces each content type with a copy carrying
 *   the `locale` / `localizations` attributes (the content-type factory already adds the same
 *   attributes, so this does not change their shape).
 */
final class Register
{
    public function __invoke(Strapi $strapi): void
    {
        $strapi->get('models')->add(AiLocalizationJob::aiLocalizationJob());

        if (!$strapi->localization()->isEnabled()) {
            $strapi->localization()->register(new LocalizationProvider($strapi));
        }

        self::extendContentTypes($strapi);
        self::addContentManagerLocaleMiddleware($strapi);

        $strapi->hook('strapi::content-types.afterSync')->register(
            static function (mixed $ctx) use ($strapi): void {
                $ctx = is_array($ctx) ? $ctx : [];
                Utils::permissions($strapi)->actions->repairPermissionsForNewlyLocalizedTypes([
                    'oldContentTypes' => $ctx['oldContentTypes'] ?? null,
                    'contentTypes' => $ctx['contentTypes'] ?? [],
                ]);
            },
        );
    }

    /**
     * Adds middleware on CM creation routes to use i18n locale passed in a specific param
     *
     * TODO: v5 if implemented in the CM => delete this middleware
     */
    public static function addContentManagerLocaleMiddleware(Strapi $strapi): void
    {
        $validateLocaleCreation = new ValidateLocaleCreation($strapi);

        $strapi->server()->api('admin')->use(static function (Context $ctx, callable $next) use ($validateLocaleCreation): mixed {
            // router.use('/content-manager/collection-types/:model') and router.use('/content-manager/single-types/:model')
            if (preg_match('~^/content-manager/(?:collection-types|single-types)/([^/]+)(?:/|$)~', $ctx->path(), $match) !== 1) {
                return $next();
            }

            if ($ctx->method() === 'POST' || $ctx->method() === 'PUT') {
                return $validateLocaleCreation($ctx, $next, rawurldecode($match[1]));
            }

            return $next();
        });
    }

    /**
     * Adds locale and localization fields to all content types
     * Even if content type is not localized, it will have these fields
     */
    public static function extendContentTypes(Strapi $strapi): void
    {
        $contentTypesService = Utils::contentTypes($strapi);
        $registry = $strapi->get('content-types');

        foreach ($strapi->contentTypes() as $uid => $contentType) {
            $isLocalized = $contentTypesService->isLocalizedContentType($contentType);

            $attributes = $contentType->attributes;

            $attributes['locale'] = [
                'writable' => true,
                'private' => !$isLocalized,
                'configurable' => false,
                'visible' => false,
                'type' => 'string',
            ];

            $attributes['localizations'] = [
                'type' => 'relation',
                'relation' => 'oneToMany',
                'target' => $contentType->uid,
                'writable' => false,
                'private' => !$isLocalized,
                'configurable' => false,
                'visible' => false,
                'unstable_virtual' => true,
                'joinColumn' => [
                    'name' => 'document_id',
                    'referencedColumn' => 'document_id',
                    'referencedTable' => Identifiers::global()->getTableName($contentType->collectionName),
                    // ensure the population will not include the results we already loaded
                    'on' => static fn (array $ctx): array => [
                        'id' => [
                            '$notIn' => array_map(static fn (array $r): mixed => $r['id'] ?? null, is_array($ctx['results'] ?? null) ? $ctx['results'] : []),
                        ],
                    ],
                ],
            ];

            $registry->set((string) $uid, self::withAttributes($contentType, $attributes));
        }

        if ($strapi->hasPlugin('graphql')) {
            Graphql::graphqlProvider($strapi)->register();
        }
    }

    /** @param array<string, array<string, mixed>> $attributes */
    private static function withAttributes(Schema $schema, array $attributes): Schema
    {
        return new Schema(
            uid: $schema->uid,
            modelType: $schema->modelType,
            kind: $schema->kind,
            modelName: $schema->modelName,
            globalId: $schema->globalId,
            collectionName: $schema->collectionName,
            plugin: $schema->plugin,
            apiName: $schema->apiName,
            category: $schema->category,
            info: $schema->info,
            options: $schema->options,
            pluginOptions: $schema->pluginOptions,
            attributes: $attributes,
            config: $schema->config,
        );
    }
}
