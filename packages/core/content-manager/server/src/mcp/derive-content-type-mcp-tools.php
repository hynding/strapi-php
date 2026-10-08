<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp;

use Strapi\ContentManager\Mcp\Handlers\CollectionHandlers;
use Strapi\ContentManager\Mcp\Handlers\SingleTypeHandlers;
use Strapi\ContentManager\Mcp\Schemas\DataSchema;
use Strapi\ContentManager\Mcp\Schemas\FiltersSchema;
use Strapi\ContentManager\Mcp\Schemas\InputSchemas;
use Strapi\ContentManager\Mcp\Schemas\OutputSchemas;
use Strapi\ContentManager\Mcp\Schemas\SortSchema;
use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\Core\Strapi;
use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of server/src/mcp/derive-content-type-mcp-tools.ts: the MCP tool definitions of the
 * displayed content-manager models. Every input/output schema is resolved per request, so RBAC
 * field and locale constraints follow the session's ability.
 *
 * A model is a content-manager DTO (`uid`, `kind`, `options`, `apiID`, formatted `attributes`);
 * the build context is `['localeCodes' => list<string>|null, 'defaultLocale' => string|null]`.
 */
final class DeriveContentTypeMcpTools
{
    private const ACTIONS = PermissionChecker::ACTIONS;

    /**
     * @param array<string, mixed> $model
     * @param array{localeCodes: list<string>|null, defaultLocale: string|null} $ctx
     * @return list<array<string, mixed>>
     */
    private static function buildCollectionTools(Strapi $strapi, array $model, array $ctx): array
    {
        $uid = (string) $model['uid'];
        $slug = Utils::slugifyUidForMcpToolName($uid);
        $apiID = (string) ($model['apiID'] ?? '');
        $draftAndPublish = ($model['options']['draftAndPublish'] ?? null) === true;
        /** @var array<string, array<string, mixed>> $attributes */
        $attributes = $model['attributes'] ?? [];
        $runtimeLocaleSchema = Permissions::buildLocaleSchema($ctx['localeCodes'], $ctx['defaultLocale']);

        $readFields = static fn (array $context): ?array => Permissions::getPermittedFields($strapi, $context['userAbility'], self::ACTIONS['read'], $uid, $attributes);
        $readOutput = static fn (array $context): ZodType => OutputSchemas::buildDocumentOutputSchema($attributes, $readFields($context));
        $locale = static fn (array $context, string $action): ZodType => Permissions::resolvePermittedLocaleSchema($strapi, $context, self::ACTIONS[$action], $uid, $ctx['localeCodes'], $ctx['defaultLocale'], $runtimeLocaleSchema);
        $writeData = static fn (array $context, string $action): ZodType => DataSchema::buildDataSchema(
            $strapi,
            $model,
            $attributes,
            Permissions::getPermittedFields($strapi, $context['userAbility'], self::ACTIONS[$action], $uid, $attributes),
        );
        $documentLocale = static fn (string $action): \Closure => static fn (array $context): ZodType => z::object([
            'documentId' => InputSchemas::documentIdSchema(),
            'locale' => $locale($context, $action),
        ]);
        $tool = static fn (string $name, string $operation, string $action, \Closure $input, \Closure $output, \Closure $handler): array => [
            'name' => $name,
            'telemetry' => ['source' => 'content-manager', 'name' => $operation],
            ...Utils::describeTool($apiID, $uid, $operation),
            'auth' => ['policies' => [['action' => self::ACTIONS[$action], 'subject' => $uid]]],
            'resolveInputSchema' => $input,
            'resolveOutputSchema' => $output,
            'createHandler' => $handler,
        ];

        $tools = [
            $tool(
                "list_{$slug}",
                'list',
                'read',
                static fn (array $context): ZodType => z::object([
                    'locale' => $locale($context, 'read'),
                    'status' => InputSchemas::statusSchema(),
                    'page' => InputSchemas::pageSchema(),
                    'pageSize' => InputSchemas::pageSizeSchema(),
                    'sort' => SortSchema::buildSortSchema($attributes, $readFields($context)),
                    'filters' => FiltersSchema::buildFiltersSchema($attributes, $readFields($context)),
                ]),
                static fn (array $context): ZodType => OutputSchemas::buildListOutputSchema($attributes, $readFields($context)),
                CollectionHandlers::createCollectionListHandler($uid),
            ),
            $tool(
                "get_{$slug}",
                'get',
                'read',
                static fn (array $context): ZodType => z::object([
                    'documentId' => InputSchemas::documentIdSchema(),
                    'locale' => $locale($context, 'read'),
                    'status' => InputSchemas::statusSchema(),
                ]),
                $readOutput,
                CollectionHandlers::createCollectionGetHandler($uid),
            ),
            $tool(
                "create_{$slug}",
                'create',
                'create',
                static fn (array $context): ZodType => z::object(['data' => $writeData($context, 'create'), 'locale' => $locale($context, 'create')]),
                $readOutput,
                CollectionHandlers::createCollectionCreateHandler($uid),
            ),
            $tool(
                "update_{$slug}",
                'update',
                'update',
                static fn (array $context): ZodType => z::object([
                    'documentId' => InputSchemas::documentIdSchema(),
                    'data' => $writeData($context, 'update'),
                    'locale' => $locale($context, 'update'),
                ]),
                $readOutput,
                CollectionHandlers::createCollectionUpdateHandler($uid),
            ),
            $tool(
                "delete_{$slug}",
                'delete',
                'delete',
                $documentLocale('delete'),
                static fn (array $context): ZodType => OutputSchemas::buildDeleteOutputSchema($attributes, $readFields($context)),
                CollectionHandlers::createCollectionDeleteHandler($uid),
            ),
        ];

        if ($draftAndPublish) {
            $tools[] = $tool("publish_{$slug}", 'publish', 'publish', $documentLocale('publish'), $readOutput, CollectionHandlers::createCollectionPublishHandler($uid));
            $tools[] = $tool(
                "unpublish_{$slug}",
                'unpublish',
                'unpublish',
                static fn (array $context): ZodType => z::object([
                    'documentId' => InputSchemas::documentIdSchema(),
                    'locale' => $locale($context, 'unpublish'),
                    'discardDraft' => z::boolean()->optional()->describe('Also discard the draft when unpublishing.'),
                ]),
                $readOutput,
                CollectionHandlers::createCollectionUnpublishHandler($uid),
            );
            $tools[] = $tool("discard_{$slug}_draft", 'discard_draft', 'discard', $documentLocale('discard'), $readOutput, CollectionHandlers::createCollectionDiscardDraftHandler($uid));
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $model
     * @param array{localeCodes: list<string>|null, defaultLocale: string|null} $ctx
     * @return list<array<string, mixed>>
     */
    private static function buildSingleTypeTools(Strapi $strapi, array $model, array $ctx): array
    {
        $uid = (string) $model['uid'];
        $slug = Utils::slugifyUidForMcpToolName($uid);
        $apiID = (string) ($model['apiID'] ?? '');
        $draftAndPublish = ($model['options']['draftAndPublish'] ?? null) === true;
        /** @var array<string, array<string, mixed>> $attributes */
        $attributes = $model['attributes'] ?? [];
        $runtimeLocaleSchema = Permissions::buildLocaleSchema($ctx['localeCodes'], $ctx['defaultLocale']);

        $readFields = static fn (array $context): ?array => Permissions::getPermittedFields($strapi, $context['userAbility'], self::ACTIONS['read'], $uid, $attributes);
        $readOutput = static fn (array $context): ZodType => OutputSchemas::buildDocumentOutputSchema($attributes, $readFields($context));
        $locale = static fn (array $context, string $action): ZodType => Permissions::resolvePermittedLocaleSchema($strapi, $context, self::ACTIONS[$action], $uid, $ctx['localeCodes'], $ctx['defaultLocale'], $runtimeLocaleSchema);
        $localeOnly = static fn (string $action): \Closure => static fn (array $context): ZodType => z::object(['locale' => $locale($context, $action)]);
        $tool = static fn (string $name, string $operation, array $policies, \Closure $input, \Closure $output, \Closure $handler): array => [
            'name' => $name,
            'telemetry' => ['source' => 'content-manager', 'name' => $operation],
            ...Utils::describeTool($apiID, $uid, $operation),
            'auth' => ['policies' => array_map(static fn (string $action): array => ['action' => self::ACTIONS[$action], 'subject' => $uid], $policies)],
            'resolveInputSchema' => $input,
            'resolveOutputSchema' => $output,
            'createHandler' => $handler,
        ];

        $resolveWriteInputSchema = static function (array $context) use ($strapi, $model, $attributes, $uid, $locale): ZodType {
            $createFields = Permissions::getPermittedFields($strapi, $context['userAbility'], self::ACTIONS['create'], $uid, $attributes);
            $updateFields = Permissions::getPermittedFields($strapi, $context['userAbility'], self::ACTIONS['update'], $uid, $attributes);
            // null means all fields permitted; union of null with anything is null (all permitted)
            $writeFields = $createFields === null || $updateFields === null ? null : [...$createFields, ...$updateFields];

            return z::object([
                'data' => DataSchema::buildDataSchema($strapi, $model, $attributes, $writeFields),
                'locale' => $locale($context, 'update'),
            ]);
        };

        $tools = [
            $tool(
                "get_{$slug}",
                'get',
                ['read'],
                static fn (array $context): ZodType => z::object(['locale' => $locale($context, 'read'), 'status' => InputSchemas::statusSchema()]),
                $readOutput,
                SingleTypeHandlers::createSingleGetHandler($uid),
            ),
            $tool("write_{$slug}", 'write', ['create', 'update'], $resolveWriteInputSchema, $readOutput, SingleTypeHandlers::createSingleWriteHandler($uid)),
            $tool(
                "delete_{$slug}",
                'delete',
                ['delete'],
                $localeOnly('delete'),
                static fn (array $context): ZodType => OutputSchemas::buildDeleteOutputSchema($attributes, $readFields($context)),
                SingleTypeHandlers::createSingleDeleteHandler($uid),
            ),
        ];

        if ($draftAndPublish) {
            $tools[] = $tool("publish_{$slug}", 'publish', ['publish'], $localeOnly('publish'), $readOutput, SingleTypeHandlers::createSinglePublishHandler($uid));
            $tools[] = $tool(
                "unpublish_{$slug}",
                'unpublish',
                ['unpublish'],
                static fn (array $context): ZodType => z::object([
                    'locale' => $locale($context, 'unpublish'),
                    'discardDraft' => z::boolean()->optional()->describe('Also discard the draft when unpublishing.'),
                ]),
                $readOutput,
                SingleTypeHandlers::createSingleUnpublishHandler($uid),
            );
            $tools[] = $tool("discard_{$slug}_draft", 'discard_draft', ['discard'], $localeOnly('discard'), $readOutput, SingleTypeHandlers::createSingleDiscardDraftHandler($uid));
        }

        return $tools;
    }

    /**
     * Builds the MCP tool definitions of the displayed content-manager models. Visibility is
     * enforced separately, by each tool's static auth and the session capability sync.
     *
     * @param list<array<string, mixed>> $models
     * @param array{localeCodes: list<string>|null, defaultLocale: string|null} $ctx
     * @return list<array<string, mixed>>
     */
    public static function deriveDisplayedContentTypeMcpToolDefinitions(Strapi $strapi, array $models, array $ctx = ['localeCodes' => null, 'defaultLocale' => null]): array
    {
        $tools = [];

        foreach ($models as $model) {
            if (($model['kind'] ?? null) === 'collectionType') {
                array_push($tools, ...self::buildCollectionTools($strapi, $model, $ctx));
            } elseif (($model['kind'] ?? null) === 'singleType') {
                array_push($tools, ...self::buildSingleTypeTools($strapi, $model, $ctx));
            }
        }

        return $tools;
    }
}
