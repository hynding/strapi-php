<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Mcp;

use Strapi\ContentManager\Mcp\DeriveContentTypeMcpTools;
use Strapi\ContentManager\Mcp\Permissions;
use Strapi\ContentManager\Mcp\Schemas\BlocksSchema;
use Strapi\ContentManager\Mcp\Schemas\DataSchema;
use Strapi\ContentManager\Mcp\Schemas\FiltersSchema;
use Strapi\ContentManager\Mcp\Schemas\OutputSchemas;
use Strapi\ContentManager\Mcp\Schemas\SortSchema;
use Strapi\ContentManager\Mcp\Utils;
use Strapi\Core\Services\Mcp\Internal\McpServerFactory;
use Strapi\Core\Services\Mcp\Sdk\McpServer;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\AbilityBuilder;
use Strapi\Tests\AppTestCase;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of server/src/mcp/__tests__/{derive-content-type-mcp-tools,blocks-schema}.test.ts and
 * schemas/__tests__/output-schemas.test.ts on the booted examples/getstarted app (upstream mocks
 * strapi and the services), plus an end-to-end tool run through the MCP server.
 */
final class DeriveContentTypeMcpToolsTest extends AppTestCase
{
    private const ARTICLE = 'api::article.article';

    private static function superAdmin(): Ability
    {
        return (new AbilityBuilder())->can('manage', 'all')->build();
    }

    /** @return array<string, array<string, mixed>> */
    private static function tools(): array
    {
        $models = \Strapi\ContentManager\Utils\Utils::getService(self::strapi(), 'content-types')->findDisplayedContentTypes();
        $tools = DeriveContentTypeMcpTools::deriveDisplayedContentTypeMcpToolDefinitions(self::strapi(), $models, ['localeCodes' => ['en', 'fr'], 'defaultLocale' => 'en']);

        return array_column($tools, null, 'name');
    }

    /** @return array<string, mixed> */
    private static function jsonSchema(ZodType $schema, string $io = 'input'): array
    {
        return json_decode((string) json_encode(McpServer::standardSchemaToJsonSchema($schema, $io)), true);
    }

    private static function accepts(ZodType $schema, mixed $value): bool
    {
        return $schema->safeParse($value)['success'] === true;
    }

    public function testSlugifyUidForMcpToolName(): void
    {
        self::assertSame('article', Utils::slugifyUidForMcpToolName('api::article.article'));
        self::assertSame('writer_editor', Utils::slugifyUidForMcpToolName('api::writer.editor'));
        self::assertSame('plugin-i18n_locale', Utils::slugifyUidForMcpToolName('plugin::i18n.locale'));
        self::assertSame('plugin-users-permissions_user', Utils::slugifyUidForMcpToolName('plugin::users-permissions.user'));
    }

    public function testDerivesToolNamesAndAuth(): void
    {
        $tools = self::tools();

        foreach (['list_article', 'get_article', 'create_article', 'update_article', 'delete_article', 'publish_article', 'unpublish_article', 'discard_article_draft'] as $name) {
            self::assertArrayHasKey($name, $tools);
        }
        self::assertSame([['action' => 'plugin::content-manager.explorer.read', 'subject' => self::ARTICLE]], $tools['list_article']['auth']['policies']);
        self::assertSame(['source' => 'content-manager', 'name' => 'discard_draft'], $tools['discard_article_draft']['telemetry']);
        self::assertSame('Content: article — list', $tools['list_article']['title']);
        self::assertStringContainsString('may return a different numeric id for the published version row', $tools['publish_article']['description']);

        // single type: unified write tool, no list/create/update
        foreach (['get_homepage', 'write_homepage', 'delete_homepage', 'publish_homepage', 'unpublish_homepage', 'discard_homepage_draft'] as $name) {
            self::assertArrayHasKey($name, $tools);
        }
        self::assertArrayNotHasKey('list_homepage', $tools);
        self::assertSame(['plugin::content-manager.explorer.create', 'plugin::content-manager.explorer.update'], array_column($tools['write_homepage']['auth']['policies'], 'action'));

        // no draft workflow tools without draft and publish
        self::assertArrayHasKey('list_like', $tools);
        self::assertArrayNotHasKey('publish_like', $tools);

        foreach ($tools as $tool) {
            self::assertNotSame('', $tool['description']);
            self::assertInstanceOf(\Closure::class, $tool['createHandler']);
        }
    }

    public function testInputSchemas(): void
    {
        $tools = self::tools();
        $context = ['userAbility' => self::superAdmin(), 'user' => ['id' => 1]];

        $list = self::jsonSchema($tools['list_article']['resolveInputSchema']($context));
        self::assertSame(['locale', 'status', 'page', 'pageSize', 'sort', 'filters'], array_keys($list['properties']));
        self::assertArrayNotHasKey('required', $list);
        self::assertSame(['en', 'fr'], $list['properties']['locale']['enum']);
        self::assertSame('en', $list['properties']['locale']['default']);

        $get = self::jsonSchema($tools['get_article']['resolveInputSchema']($context));
        self::assertSame(['documentId'], $get['required']);
        self::assertStringContainsString('canonical identifier across draft/published versions', $get['properties']['documentId']['description']);

        self::assertSame(['data'], self::jsonSchema($tools['create_article']['resolveInputSchema']($context))['required']);
        self::assertSame(['documentId', 'data'], self::jsonSchema($tools['update_article']['resolveInputSchema']($context))['required']);
        self::assertEquals(['type' => 'boolean', 'description' => 'Also discard the draft when unpublishing.'], self::jsonSchema($tools['unpublish_article']['resolveInputSchema']($context))['properties']['discardDraft']);

        // a content type that is not localized
        $tag = self::jsonSchema($tools['list_like']['resolveInputSchema']($context));
        self::assertSame('This content type is not localized. Locale is ignored.', $tag['properties']['locale']['description']);
    }

    public function testNarrowsSchemasToPermittedFields(): void
    {
        $tools = self::tools();
        $ability = (new AbilityBuilder())
            ->can('plugin::content-manager.explorer.read', self::ARTICLE, ['title'])
            ->can('plugin::content-manager.explorer.create', self::ARTICLE, ['title', 'authorName'])
            ->build();
        $context = ['userAbility' => $ability, 'user' => ['id' => 1]];

        $create = self::jsonSchema($tools['create_article']['resolveInputSchema']($context));
        self::assertSame(['title', 'authorName'], array_keys($create['properties']['data']['properties']));

        $list = self::jsonSchema($tools['list_article']['resolveInputSchema']($context));
        self::assertStringContainsString('Valid fields: title.', $list['properties']['sort']['description']);

        $output = self::jsonSchema($tools['get_article']['resolveOutputSchema']($context), 'output');
        self::assertSame(['title'], array_keys($output['properties']['data']['anyOf'][0]['properties'] ?? $output['properties']['data']['properties'] ?? []));

        self::assertSame(['title' => true], Permissions::getPermittedFields(self::strapi(), $ability, 'plugin::content-manager.explorer.read', self::ARTICLE, ['title' => ['type' => 'string'], 'body' => ['type' => 'text']]));
        self::assertNull(Permissions::getPermittedFields(self::strapi(), self::superAdmin(), 'plugin::content-manager.explorer.read', self::ARTICLE, ['title' => ['type' => 'string']]));
    }

    public function testOutputSchemasAreLooseAndDeleteAcceptsEmptyData(): void
    {
        $attributes = ['title' => ['type' => 'string'], 'tags' => ['type' => 'relation', 'relation' => 'manyToMany', 'target' => 'api::tag.tag'], 'author' => ['type' => 'relation', 'relation' => 'manyToOne', 'target' => 'admin::user']];
        $document = OutputSchemas::buildDocumentOutputSchema($attributes, null);

        self::assertTrue(self::accepts($document, ['data' => ['title' => 'x', 'status' => 'draft', 'tags' => [['documentId' => 'a', 'locale' => 'en']], 'author' => ['id' => 1, 'email' => 'x']]]));
        self::assertFalse(self::accepts($document, ['data' => ['tags' => [['id' => 1]]]]), 'a relation must be identity-shaped');
        self::assertTrue(self::accepts($document, ['data' => null]));
        self::assertTrue(self::accepts(OutputSchemas::buildDeleteOutputSchema($attributes, null), ['data' => []]));
        self::assertTrue(self::accepts(OutputSchemas::buildListOutputSchema($attributes, ['title' => true]), ['results' => [['title' => 'x', 'extra' => 1]], 'pagination' => ['page' => 1, 'pageSize' => 10, 'pageCount' => 1, 'total' => 1]]));
    }

    public function testBuildDataSchema(): void
    {
        $strapi = self::strapi();
        $model = $strapi->contentTypes()['api::kitchensink.kitchensink'];
        $attributes = $model->attributes;
        $schema = DataSchema::buildDataSchema($strapi, $model, $attributes);
        $json = self::jsonSchema(\Strapi\Utils\Zod\Z::object(['data' => $schema]));
        $properties = $json['properties']['data']['properties'];

        foreach (['id', 'documentId', 'createdAt', 'updatedAt', 'createdBy', 'updatedBy'] as $system) {
            self::assertArrayNotHasKey($system, $properties);
        }
        self::assertSame(['type' => 'integer', 'minimum' => -9007199254740991, 'maximum' => 9007199254740991], array_intersect_key($properties['integer'], ['type' => 1, 'minimum' => 1, 'maximum' => 1]) + ['type' => 'integer'] + []);
        self::assertSame('object', $properties['single_compo']['type']);
        self::assertSame('array', $properties['repeatable_compo']['type']);

        self::assertTrue(self::accepts($schema, ['short_text' => 'x', 'boolean' => true]), (string) json_encode($schema->safeParse(['short_text' => 'x', 'boolean' => true])['error']?->issues));
        self::assertFalse(self::accepts($schema, ['unknown_field' => 1]), 'strict');
        self::assertFalse(self::accepts($schema, ['integer' => 'nope']));

        // xOne relations
        foreach (['doc1', ['documentId' => 'doc1', 'locale' => 'en', 'status' => 'draft'], null] as $value) {
            self::assertTrue(self::accepts($schema, ['one_way_tag' => $value]));
        }
        foreach (['', 5, ['documentId' => 'doc1', 'extra' => 1]] as $value) {
            self::assertFalse(self::accepts($schema, ['one_way_tag' => $value]));
        }

        // xMany relations
        foreach ([['connect' => ['a', ['documentId' => 'b', 'position' => ['before' => 'a']]]], ['disconnect' => ['a']], ['set' => ['a']], ['set' => null], []] as $value) {
            self::assertTrue(self::accepts($schema, ['many_to_many_tags' => $value]), (string) json_encode($value));
        }
        foreach ([['set' => ['a'], 'connect' => ['b']], ['set' => null, 'disconnect' => ['b']], ['a', 'b'], ['connect' => [1]], ['other' => []]] as $value) {
            self::assertFalse(self::accepts($schema, ['many_to_many_tags' => $value]), (string) json_encode($value));
        }
        $result = $schema->safeParse(['many_to_many_tags' => ['set' => ['a'], 'connect' => ['b']]]);
        self::assertSame('set is mutually exclusive with connect and disconnect', $result['error']->issues[0]['message'] ?? null);

        // unknown / circular components fall back to a record
        self::assertSame('object', self::jsonSchema(\Strapi\Utils\Zod\Z::object(['c' => DataSchema::buildComponentInputSchema($strapi, 'unknown.component')]))['properties']['c']['type']);
        self::assertSame(['type' => 'object', 'propertyNames' => ['type' => 'string'], 'additionalProperties' => []], self::jsonSchema(\Strapi\Utils\Zod\Z::object(['c' => DataSchema::buildComponentInputSchema($strapi, 'basic.simple', ['basic.simple' => true])]))['properties']['c']);

        // private attributes and non-permitted fields are left out
        $schema = DataSchema::buildDataSchema($strapi, ['uid' => 'x', 'attributes' => ['a' => ['type' => 'string'], 'secret' => ['type' => 'string', 'private' => true], 'b' => ['type' => 'string']]], ['a' => ['type' => 'string'], 'secret' => ['type' => 'string', 'private' => true], 'b' => ['type' => 'string']], ['a' => true, 'secret' => true]);
        self::assertSame(['a'], array_keys(self::jsonSchema(\Strapi\Utils\Zod\Z::object(['d' => $schema]))['properties']['d']['properties']));
    }

    public function testBlocksSchema(): void
    {
        $blocks = BlocksSchema::buildBlocksInputSchema();
        $text = ['type' => 'text', 'text' => 'Hello', 'bold' => true];

        self::assertTrue(self::accepts($blocks, [
            ['type' => 'paragraph', 'children' => [$text, ['type' => 'link', 'url' => 'https://strapi.io', 'children' => [$text]]]],
            ['type' => 'heading', 'level' => 2, 'children' => [$text]],
            ['type' => 'quote', 'children' => [$text]],
            ['type' => 'code', 'language' => null, 'children' => [$text]],
            ['type' => 'list', 'format' => 'ordered', 'children' => [['type' => 'list-item', 'children' => [$text]], ['type' => 'list', 'format' => 'unordered', 'children' => [['type' => 'list-item', 'children' => [$text]]]]]],
        ]));
        self::assertFalse(self::accepts($blocks, [['type' => 'heading', 'level' => 7, 'children' => [$text]]]));
        self::assertFalse(self::accepts($blocks, [['type' => 'paragraph', 'children' => []]]));
        self::assertFalse(self::accepts($blocks, [['type' => 'video', 'children' => [$text]]]));
        self::assertFalse(self::accepts($blocks, [['type' => 'image', 'image' => ['name' => 'x'], 'children' => [['type' => 'text', 'text' => '']]]]));
        self::assertSame($blocks, BlocksSchema::buildBlocksInputSchema(), 'one instance');
        self::assertArrayHasKey('$defs', self::jsonSchema(\Strapi\Utils\Zod\Z::object(['b' => $blocks])), 'the recursive list is a $ref');
    }

    public function testSortAndFiltersSchemas(): void
    {
        $attributes = ['title' => ['type' => 'string'], 'views' => ['type' => 'integer'], 'secret' => ['type' => 'string', 'private' => true], 'cover' => ['type' => 'media']];
        self::assertSame(['title', 'views'], SortSchema::getScalarAttributeKeys($attributes));

        $sort = SortSchema::buildSortSchema($attributes);
        foreach (['title:asc', ['title:asc', 'views:desc'], ['title' => 'asc'], [['views' => 'desc']], null] as $value) {
            self::assertTrue(self::accepts($sort->optional(), $value ?? \Strapi\Utils\Zod\Undefined::Value), (string) json_encode($value));
        }
        self::assertFalse(self::accepts($sort, ['title' => 'up']));
        self::assertFalse(self::accepts($sort, 'secret:asc'));
        self::assertFalse(self::accepts($sort, ['cover' => 'asc']));
        self::assertFalse(self::accepts(SortSchema::buildSortSchema(['cover' => ['type' => 'media']]), 'x'), 'never');
        self::assertFalse(self::accepts(SortSchema::buildSortSchema($attributes, ['views' => true]), 'title:asc'));

        $filters = FiltersSchema::buildFiltersSchema($attributes);
        self::assertTrue(self::accepts($filters, ['title' => 'x']));
        self::assertTrue(self::accepts($filters, ['$or' => [['title' => ['$containsi' => 'x']], ['views' => ['$gt' => 5]]], '$not' => ['views' => [1, 2]]]));
        self::assertFalse(self::accepts($filters, ['secret' => 'x']));
        self::assertFalse(self::accepts($filters, ['views' => ['$gt' => 'five']]));
        self::assertStringContainsString('Valid fields: title, views.', (string) self::jsonSchema(\Strapi\Utils\Zod\Z::object(['f' => $filters]))['properties']['f']['description']);
    }

    public function testComponentLeafPathsAndLocales(): void
    {
        $paths = Permissions::getComponentLeafPaths(self::strapi(), 'basic.simple', 'single_compo');
        self::assertNotSame([], $paths);
        self::assertStringStartsWith('single_compo.', $paths[0]);
        self::assertSame(['x'], Permissions::getComponentLeafPaths(self::strapi(), 'unknown.uid', 'x'));
        self::assertSame(['x'], Permissions::getComponentLeafPaths(self::strapi(), 'basic.simple', 'x', ['basic.simple' => true]));

        $schema = Permissions::buildLocaleSchema(null, null);
        self::assertSame('Locale code (e.g. "en", "fr"). Defaults to the default locale.', self::jsonSchema(\Strapi\Utils\Zod\Z::object(['l' => $schema]))['properties']['l']['description']);

        $fr = (new AbilityBuilder())->can('plugin::content-manager.explorer.read', self::ARTICLE, null, ['locale' => 'fr'])->build();
        $narrowed = Permissions::resolvePermittedLocaleSchema(self::strapi(), ['userAbility' => $fr], 'plugin::content-manager.explorer.read', self::ARTICLE, ['en', 'fr'], 'en', Permissions::buildLocaleSchema(['en', 'fr'], 'en'));
        $json = self::jsonSchema(\Strapi\Utils\Zod\Z::object(['l' => $narrowed]))['properties']['l'];
        self::assertSame(['fr'], $json['enum']);
        self::assertSame('Locale code. Permitted: fr. Defaults to the default locale.', $json['description']);
    }

    public function testToolsRunThroughTheMcpServer(): void
    {
        $strapi = self::strapi();
        $owner = $strapi->service('admin::user')->create(['email' => 'mcp-owner@strapi.io', 'firstname' => 'mcp', 'lastname' => 'owner', 'isActive' => true]);
        $server = McpServerFactory::createMcpServerWithRegistries([
            'strapi' => $strapi,
            'definitions' => $strapi->ai()->mcp()->capabilityDefinitions(),
            'isDevMode' => false,
            'ability' => self::superAdmin(),
            'user' => ['id' => $owner['id']],
        ])['mcpServer'];
        $call = static function (string $name, array $arguments) use ($server): array {
            $response = $server->handleMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]);
            $result = json_decode((string) json_encode($response['result'] ?? $response), true);
            self::assertArrayNotHasKey('isError', $result, (string) json_encode($result));

            return $result['structuredContent'];
        };

        $names = array_column($server->handleMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'] ?? [], 'name');
        self::assertContains('create_article', $names);
        self::assertContains('media_list_assets', $names, 'the upload tools are registered too');

        $category = $call('create_category', ['data' => ['name' => 'mcp-category'], 'locale' => 'en'])['data'];
        $created = $call('create_article', ['data' => ['title' => 'via mcp', 'categories' => ['connect' => [$category['documentId']]]], 'locale' => 'en'])['data'];
        self::assertSame('via mcp', $created['title']);
        self::assertSame('draft', $created['status']);

        $got = $call('get_article', ['documentId' => $created['documentId']]);
        self::assertSame('via mcp', $got['data']['title']);
        self::assertArrayHasKey('meta', $got);
        self::assertSame([['documentId' => $category['documentId'], 'locale' => 'en']], $got['data']['categories'], 'relations are reduced to their identity');

        $list = $call('list_article', ['filters' => ['title' => ['$eq' => 'via mcp']]]);
        self::assertSame(1, $list['pagination']['total']);
        self::assertSame($created['documentId'], $list['results'][0]['documentId']);

        $published = $call('publish_article', ['documentId' => $created['documentId']])['data'];
        self::assertSame('published', $published['status']);
        $call('unpublish_article', ['documentId' => $created['documentId'], 'discardDraft' => false]);
        self::assertSame([], $call('delete_article', ['documentId' => $created['documentId']])['data']);

        $missing = json_decode((string) json_encode($server->handleMessage(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_article', 'arguments' => ['documentId' => 'nope']]])), true);
        self::assertSame('Tool "get_article" execution failed: Document not found.', $missing['result']['content'][0]['text']);
    }
}
