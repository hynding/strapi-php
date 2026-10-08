<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Tests;

require_once __DIR__ . '/BootedApp.php';

use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\GraphqlDepthLimit;
use Strapi\Plugin\Graphql\Services\ContentApi\ContentApi;

/**
 * The `/graphql` endpoint of a booted app: Apollo Server's HTTP behaviour (landing page, CSRF
 * prevention, request validation, status codes, error formatting) and the generated schema's
 * names. Not an upstream unit test: upstream covers these through Apollo's own suite and
 * tests/api.
 */
final class GraphqlEndpointTest extends TestCase
{
    private static Strapi $strapi;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedApp::shared();
    }

    private static function schema(): Schema
    {
        $contentApi = self::$strapi->plugin('graphql')->service('content-api');
        self::assertInstanceOf(ContentApi::class, $contentApi);

        return $contentApi->buildSchema();
    }

    /**
     * The app runs in development, where Apollo adds `extensions.stacktrace` to errors.
     */
    private static function withoutStacktraces(mixed $body): mixed
    {
        if (is_array($body) && is_array($body['errors'] ?? null)) {
            foreach ($body['errors'] as $i => $error) {
                unset($body['errors'][$i]['extensions']['stacktrace']);
            }
        }

        return $body;
    }

    public function testServesApolloLandingPageToBrowsers(): void
    {
        $response = BootedApp::decode(BootedApp::request(self::$strapi, 'GET', '/graphql', ['Accept' => 'text/html']));

        self::assertSame(200, $response['status']);
        self::assertStringStartsWith('text/html', $response['headers']['content-type']);
        self::assertIsString($response['body']);
        self::assertStringContainsString('<title>Apollo Server</title>', $response['body']);
        self::assertStringContainsString('embeddable-sandbox.cdn.apollographql.com/v2/embeddable-sandbox.umd.production.min.js?runtime=%40apollo%2Fserver%404.13.0', $response['body']);
    }

    public function testExecutesAQuery(): void
    {
        $response = BootedApp::graphql(self::$strapi, ['query' => '{ __typename }']);

        self::assertSame(200, $response['status']);
        self::assertSame('application/json; charset=utf-8', $response['headers']['content-type']);
        self::assertSame(['data' => ['__typename' => 'Query']], $response['body']);
    }

    public function testExecutesAQueryFromAGetRequest(): void
    {
        $response = BootedApp::decode(BootedApp::request(self::$strapi, 'GET', '/graphql?query=' . rawurlencode('{ __typename }'), ['Content-Type' => 'application/json']));

        self::assertSame(200, $response['status']);
        self::assertSame(['data' => ['__typename' => 'Query']], $response['body']);
    }

    public function testBlocksPotentialCsrfRequests(): void
    {
        $response = BootedApp::decode(BootedApp::request(self::$strapi, 'GET', '/graphql?query=' . rawurlencode('{ __typename }')));

        self::assertSame(400, $response['status']);
        self::assertIsArray($response['body']);
        self::assertSame('BAD_REQUEST', $response['body']['errors'][0]['extensions']['code']);
        self::assertStringStartsWith('This operation has been blocked as a potential Cross-Site Request Forgery (CSRF).', $response['body']['errors'][0]['message']);
    }

    public function testRejectsMutationsOverGet(): void
    {
        $response = BootedApp::decode(BootedApp::request(self::$strapi, 'GET', '/graphql?query=' . rawurlencode('mutation { __typename }'), ['Content-Type' => 'application/json']));

        self::assertSame(405, $response['status']);
        self::assertSame('POST', $response['headers']['allow']);
        self::assertSame([
            'errors' => [[
                'message' => 'GET requests only support query operations, not mutation operations',
                'extensions' => ['code' => 'BAD_REQUEST'],
            ]],
        ], self::withoutStacktraces($response['body']));
    }

    public function testRejectsAnEmptyPostBody(): void
    {
        $response = BootedApp::graphql(self::$strapi, []);

        self::assertSame(400, $response['status']);
        self::assertIsArray($response['body']);
        self::assertSame('POST body missing, invalid Content-Type, or JSON object has no keys.', $response['body']['errors'][0]['message']);
    }

    public function testRejectsBatchesWhenBatchingIsDisabled(): void
    {
        $response = BootedApp::decode(BootedApp::request(self::$strapi, 'POST', '/graphql', ['Content-Type' => 'application/json'], '[{"query":"{ __typename }"}]'));

        self::assertSame(400, $response['status']);
        self::assertIsArray($response['body']);
        self::assertSame('Operation batching disabled.', $response['body']['errors'][0]['message']);
    }

    public function testReportsSyntaxErrors(): void
    {
        $response = BootedApp::graphql(self::$strapi, ['query' => '{ articles ']);

        self::assertSame(400, $response['status']);
        self::assertIsArray($response['body']);
        self::assertSame('GRAPHQL_PARSE_FAILED', $response['body']['errors'][0]['extensions']['code']);
        self::assertSame([['line' => 1, 'column' => 12]], $response['body']['errors'][0]['locations']);
    }

    public function testReportsValidationErrors(): void
    {
        $response = BootedApp::graphql(self::$strapi, ['query' => '{ articles { unknownField } }']);

        self::assertSame(400, $response['status']);
        self::assertSame([
            'errors' => [[
                'message' => 'Cannot query field "unknownField" on type "Article".',
                'locations' => [['line' => 1, 'column' => 14]],
                'extensions' => ['code' => 'GRAPHQL_VALIDATION_FAILED'],
            ]],
        ], self::withoutStacktraces($response['body']));
    }

    public function testReportsVariableCoercionErrorsAsBadUserInput(): void
    {
        $response = BootedApp::graphql(self::$strapi, [
            'query' => 'query ($id: ID!) { article(documentId: $id) { documentId } }',
            'variables' => [],
        ]);

        self::assertSame(400, $response['status']);
        self::assertIsArray($response['body']);
        self::assertSame('Variable "$id" of required type "ID!" was not provided.', $response['body']['errors'][0]['message']);
        self::assertSame('BAD_USER_INPUT', $response['body']['errors'][0]['extensions']['code']);
        self::assertArrayNotHasKey('data', $response['body']);
    }

    public function testFormatsStrapiErrorsWithTheirCodeAndDetails(): void
    {
        // the public role has no permission on articles
        $response = BootedApp::graphql(self::$strapi, ['query' => '{ articles { documentId } }']);

        self::assertSame(200, $response['status']);
        self::assertSame([
            'errors' => [[
                'message' => 'Forbidden access',
                'path' => ['articles'],
                'extensions' => [
                    'code' => 'FORBIDDEN',
                    'error' => ['name' => 'ForbiddenError', 'message' => 'Forbidden access', 'details' => []],
                ],
            ]],
            'data' => null,
        ], self::withoutStacktraces($response['body']));
    }

    public function testDepthLimitRule(): void
    {
        $schema = self::schema();
        $document = Parser::parse('query deep { articles { categories { articles { documentId } } } }');

        $errors = DocumentValidator::validate($schema, $document, [new GraphqlDepthLimit(2)]);
        self::assertCount(1, $errors);
        self::assertSame("'deep' exceeds maximum operation depth of 2", $errors[0]->getMessage());

        self::assertSame([], DocumentValidator::validate($schema, $document, [new GraphqlDepthLimit(3)]));
        // an unset limit never triggers (graphql-depth-limit compares with `undefined`)
        self::assertSame([], DocumentValidator::validate($schema, $document, [new GraphqlDepthLimit(null)]));
    }

    public function testGeneratesUpstreamTypeNames(): void
    {
        $schema = self::schema();

        foreach ([
            'Article', 'ArticleEntityResponseCollection', 'ArticleRelationResponseCollection', 'ArticleInput', 'ArticleFiltersInput',
            'Pagination', 'PaginationArg', 'PublicationStatus', 'PublicationFilter', 'DeleteMutationResponse',
            'StringFilterInput', 'IDFilterInput', 'DateTimeFilterInput', 'JSON', 'DateTime', 'Long', 'Time', 'Date', 'I18NLocaleCode',
            'UploadFile', 'UsersPermissionsUser', 'UsersPermissionsMe', 'UsersPermissionsLoginPayload', 'GenericMorph', 'Error',
        ] as $typeName) {
            self::assertTrue($schema->hasType($typeName), "missing type {$typeName}");
        }

        $query = $schema->getQueryType();
        self::assertNotNull($query);
        foreach (['article', 'articles', 'articles_connection', 'me', 'uploadFiles', 'i18NLocales'] as $field) {
            self::assertTrue($query->hasField($field), "missing Query.{$field}");
        }
        self::assertSame('[Article]!', (string) $query->getField('articles')->getType());
        self::assertSame(['filters', 'pagination', 'sort', 'status', 'hasPublishedVersion', 'publicationFilter', 'locale'], array_map(static fn ($arg): string => $arg->name, $query->getField('articles')->args));

        $mutation = $schema->getMutationType();
        self::assertNotNull($mutation);
        foreach (['createArticle', 'updateArticle', 'deleteArticle', 'login', 'register', 'updateUploadFile', 'deleteUploadFile', 'createUsersPermissionsRole'] as $field) {
            self::assertTrue($mutation->hasField($field), "missing Mutation.{$field}");
        }
        self::assertFalse($mutation->hasField('createUploadFile'));
        self::assertFalse($mutation->hasField('createI18NLocale'));

        // only referenced by the v4 compatibility `meta` field: pruned
        self::assertFalse($schema->hasType('ResponseCollectionMeta'));
    }
}
