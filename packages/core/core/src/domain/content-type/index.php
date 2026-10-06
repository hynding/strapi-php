<?php

declare(strict_types=1);

namespace Strapi\Core\Domain\ContentType;

use Strapi\Database\Utils\LodashWords;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes as ContentTypesUtils;
use Strapi\Utils\Errors\YupValidationError;

/**
 * Port of packages/core/core/src/domain/content-type/index.ts (`createContentType`, `getGlobalId`).
 *
 * The attribute additions (timestamps, publishedAt, creator fields, i18n locale/localizations)
 * live in {@see SchemaFactory::contentType()} so the database package can build the same schemas
 * without core; this file validates the definition and delegates.
 *
 * @phpstan-type ContentTypeDefinition array{schema: array<string, mixed>, actions?: array<string, mixed>, lifecycles?: array<string, mixed>}
 */
final class ContentType
{
    /** @var callable(string): void|null */
    private static $warn = null;

    /** Where `warnDraftAndPublishReservedAttributes` logs (upstream: strapi.log.warn). */
    public static function setWarningHandler(?callable $handler): void
    {
        self::$warn = $handler;
    }

    /** @param ContentTypeDefinition $definition */
    public static function createContentType(string $uid, array $definition): Schema
    {
        try {
            Validator::validateContentTypeDefinition($definition);
        } catch (YupValidationError $e) {
            $messages = implode(',', array_map(static fn (array $err): string => $err['message'], $e->errors()));

            throw new \RuntimeException("Content Type Definition is invalid for {$uid}'.\n{$messages}", 0, $e);
        }

        $schema = $definition['schema'];
        $lifecycles = $definition['lifecycles'] ?? [];
        $actions = $definition['actions'] ?? [];

        // __schema__: the raw definition is kept in config so the content-type-builder can read it back
        $schema['config'] = [
            ...($schema['config'] ?? []),
            '__schema__' => self::pickSchema($schema),
            'actions' => $actions,
        ];

        $contentType = SchemaFactory::contentType($schema, $uid, $lifecycles);

        self::warnDraftAndPublishReservedAttributes($uid, $contentType);

        return $contentType;
    }

    private static function warnDraftAndPublishReservedAttributes(string $uid, Schema $schema): void
    {
        if (($schema->options['draftAndPublish'] ?? null) !== true) {
            return;
        }

        foreach (ContentTypesUtils::findDraftAndPublishReservedAttributeNames(array_keys($schema->attributes)) as $attributeName) {
            $message = ContentTypesUtils::getDraftAndPublishReservedAttributeWarning($uid, $attributeName);
            if (self::$warn !== null) {
                (self::$warn)($message);
            }
        }
    }

    /** @param array<string, mixed> $schema */
    public static function getGlobalId(array $schema, ?string $prefix = null): string
    {
        $modelName = (string) ($schema['info']['singularName'] ?? '');
        $globalId = $prefix !== null ? "{$prefix}-{$modelName}" : $modelName;

        return (string) ($schema['globalId'] ?? LodashWords::upperFirst(LodashWords::camelCase($globalId)));
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private static function pickSchema(array $model): array
    {
        $schema = array_intersect_key($model, array_flip([
            'connection', 'collectionName', 'info', 'options', 'pluginOptions', 'attributes', 'kind', 'indexes', 'foreignKeys',
        ]));
        $schema['kind'] = $model['kind'] ?? 'collectionType';

        return $schema;
    }
}
