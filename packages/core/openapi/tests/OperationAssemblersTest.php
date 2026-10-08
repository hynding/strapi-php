<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation\BodyAssembler;
use Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation\OperationParametersAssembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Context\Factories\OperationContextFactory;
use Strapi\Openapi\Tests\Mocks\StrapiConfigMock;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of __tests__/operation-assemblers.test.ts.
 *
 * Params registered via strapi.contentAPI.addQueryParams / addInputParams are merged into each
 * route's request.query and request.body (by the content-api service). The OpenAPI spec generator
 * reads route.request when assembling parameters and body. These tests (1) call
 * addQueryParams/addInputParams, (2) simulate merging those params into routes, (3) run the
 * assemblers and assert the generated spec includes the params.
 */
final class OperationAssemblersTest extends TestCase
{
    /**
     * Minimal content-api route for tests; pass overrides (e.g. method, path, request) as needed.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function contentAPIRoute(array $overrides = []): array
    {
        return [
            'method' => 'GET',
            'path' => '/api/articles',
            'handler' => '',
            'info' => ['type' => 'content-api'],
            ...$overrides,
        ];
    }

    /** The `createMockContentAPI()` of upstream (resolved param entries + merge helpers). */
    private static function createMockContentAPI(): object
    {
        return new class () {
            /** @var list<array{param: string, schema: ZodType, matchRoute?: callable}> */
            private array $extraQueryParams = [];

            /** @var list<array{param: string, schema: ZodType, matchRoute?: callable}> */
            private array $extraInputParams = [];

            /** @param array<string, array{schema: ZodType, matchRoute?: callable}> $options */
            public function addQueryParams(array $options): void
            {
                foreach ($options as $param => $rest) {
                    $this->extraQueryParams[] = ['param' => (string) $param, ...$rest];
                }
            }

            /** @param array<string, array{schema: ZodType, matchRoute?: callable}> $options */
            public function addInputParams(array $options): void
            {
                foreach ($options as $param => $rest) {
                    $this->extraInputParams[] = ['param' => (string) $param, ...$rest];
                }
            }

            /**
             * @param array<string, mixed> $route
             *
             * @return array<string, mixed>
             */
            public function mergeQueryParamsIntoRoute(array $route): array
            {
                $query = $route['request']['query'] ?? [];
                foreach ($this->extraQueryParams as $entry) {
                    if (!isset($entry['matchRoute']) || ($entry['matchRoute'])($route)) {
                        $query[$entry['param']] = $entry['schema'];
                    }
                }

                return [...$route, 'request' => [...($route['request'] ?? []), 'query' => $query]];
            }

            /**
             * @param array<string, mixed> $route
             *
             * @return array<string, mixed>
             */
            public function mergeInputParamsIntoRoute(array $route): array
            {
                $extra = [];
                foreach ($this->extraInputParams as $entry) {
                    if (!isset($entry['matchRoute']) || ($entry['matchRoute'])($route)) {
                        $extra[$entry['param']] = $entry['schema'];
                    }
                }
                $body = ['application/json' => z::object($extra)];

                return [...$route, 'request' => [...($route['request'] ?? []), 'body' => $body]];
            }
        };
    }

    private function createOperationContext(): Context
    {
        return (new OperationContextFactory())->create(['strapi' => new StrapiConfigMock(), 'routes' => []], []);
    }

    /**
     * @param list<array<string, mixed>> $parameters
     *
     * @return array<string, mixed>|null
     */
    private static function find(array $parameters, string $name): ?array
    {
        foreach ($parameters as $parameter) {
            if ($parameter['name'] === $name && $parameter['in'] === 'query') {
                return $parameter;
            }
        }

        return null;
    }

    // --- OperationParametersAssembler ------------------------------------------------------

    public function testIncludesInTheSpecQueryParamsRegisteredWithAddQueryParams(): void
    {
        $contentAPI = self::createMockContentAPI();
        $contentAPI->addQueryParams([
            'search' => ['schema' => z::string()->max(200)->optional()],
        ]);

        $route = $contentAPI->mergeQueryParamsIntoRoute(self::contentAPIRoute(['handler' => 'api::article.article.findMany']));

        $assembler = new OperationParametersAssembler();
        $context = $this->createOperationContext();
        $assembler->assemble($context, $route);

        $searchParam = self::find($context->output->data['parameters'] ?? [], 'search');
        self::assertNotNull($searchParam);
        self::assertFalse($searchParam['required']);
        self::assertNotEmpty((array) $searchParam['schema']);
        self::assertSame('querystring', $searchParam['x-strapi-serialize']);
    }

    public function testRespectsMatchRouteWhenMergingAddQueryParamsIntoRoutes(): void
    {
        $contentAPI = self::createMockContentAPI();
        $contentAPI->addQueryParams([
            'search' => [
                'schema' => z::string()->optional(),
                'matchRoute' => static fn (array $r): bool => str_contains((string) $r['path'], 'articles'),
            ],
        ]);

        $articlesRoute = $contentAPI->mergeQueryParamsIntoRoute(self::contentAPIRoute());
        $otherRoute = $contentAPI->mergeQueryParamsIntoRoute(self::contentAPIRoute(['path' => '/api/categories']));

        $assembler = new OperationParametersAssembler();
        $ctxArticles = $this->createOperationContext();
        $ctxOther = $this->createOperationContext();
        $assembler->assemble($ctxArticles, $articlesRoute);
        $assembler->assemble($ctxOther, $otherRoute);

        self::assertNotNull(self::find($ctxArticles->output->data['parameters'] ?? [], 'search'));
        self::assertNull(self::find($ctxOther->output->data['parameters'] ?? [], 'search'));
    }

    public function testProducesNoQueryParametersWhenRouteHasNoRequestQuery(): void
    {
        $assembler = new OperationParametersAssembler();
        $context = $this->createOperationContext();
        $assembler->assemble($context, self::contentAPIRoute(['handler' => 'api::article.article.findMany']));

        self::assertSame([], $context->output->data['parameters']);
    }

    public function testExpandsNestedPaginationObjectIntoBracketNotationQueryParams(): void
    {
        $paginationSchema = z::intersection(
            z::object([
                'withCount' => z::boolean()->optional(),
            ]),
            z::union([
                z::object([
                    'page' => z::number()->int()->positive(),
                    'pageSize' => z::number()->int()->positive(),
                ]),
                z::object([
                    'start' => z::number()->int()->min(0),
                    'limit' => z::number()->int()->positive(),
                ]),
            ]),
        )->optional();

        $route = self::contentAPIRoute([
            'handler' => 'api::article.article.findMany',
            'request' => [
                'query' => [
                    'pagination' => $paginationSchema,
                ],
            ],
        ]);

        $assembler = new OperationParametersAssembler();
        $context = $this->createOperationContext();
        $assembler->assemble($context, $route);

        $names = array_map(static fn (array $p): string => $p['name'], $context->output->data['parameters'] ?? []);

        self::assertContains('pagination[page]', $names);
        self::assertContains('pagination[pageSize]', $names);
        self::assertContains('pagination[start]', $names);
        self::assertContains('pagination[limit]', $names);
        self::assertContains('pagination[withCount]', $names);
        self::assertNotContains('pagination', $names);
    }

    public function testDescribesFiltersAsADeepObjectQueryParam(): void
    {
        $filtersSchema = z::record(z::string(), z::any())->optional();

        $route = self::contentAPIRoute([
            'handler' => 'api::article.article.findMany',
            'request' => [
                'query' => [
                    'filters' => $filtersSchema,
                ],
            ],
        ]);

        $assembler = new OperationParametersAssembler();
        $context = $this->createOperationContext();
        $assembler->assemble($context, $route);

        $parameters = $context->output->data['parameters'] ?? [];
        $filtersParam = self::find($parameters, 'filters');

        self::assertNotNull($filtersParam);
        self::assertSame('deepObject', $filtersParam['style']);
        self::assertTrue($filtersParam['explode']);
        self::assertIsArray($filtersParam['schema']);
        self::assertSame('object', $filtersParam['schema']['type']);
        foreach ($parameters as $p) {
            self::assertStringStartsNotWith('filters[', $p['name']);
        }
    }

    public function testKeepsObscureQueryParamsWhenAllOfMergeYieldsNoProperties(): void
    {
        $obscureSchema = z::intersection(z::object([]), z::object([]))->optional();

        $route = self::contentAPIRoute([
            'handler' => 'api::article.article.findMany',
            'request' => [
                'query' => [
                    'obscure' => $obscureSchema,
                ],
            ],
        ]);

        $assembler = new OperationParametersAssembler();
        $context = $this->createOperationContext();
        $assembler->assemble($context, $route);

        self::assertNotNull(self::find($context->output->data['parameters'] ?? [], 'obscure'));
    }

    // --- BodyAssembler ---------------------------------------------------------------------

    public function testIncludesInTheSpecInputParamsRegisteredWithAddInputParams(): void
    {
        $contentAPI = self::createMockContentAPI();
        $contentAPI->addInputParams([
            'clientMutationId' => ['schema' => z::string()->max(100)->optional()],
        ]);

        $route = $contentAPI->mergeInputParamsIntoRoute(self::contentAPIRoute(['method' => 'POST', 'handler' => 'api::article.article.create']));

        $assembler = new BodyAssembler();
        $context = $this->createOperationContext();
        $assembler->assemble($context, $route);

        $requestBody = $context->output->data['requestBody'] ?? null;
        self::assertIsArray($requestBody);
        self::assertArrayHasKey('schema', $requestBody['content']['application/json']);
    }

    public function testDoesNotSetRequestBodyWhenRouteHasNoRequestBody(): void
    {
        $assembler = new BodyAssembler();
        $context = $this->createOperationContext();
        $assembler->assemble($context, self::contentAPIRoute(['method' => 'POST', 'handler' => 'api::article.article.create']));

        self::assertArrayNotHasKey('requestBody', $context->output->data);
    }

    /** PHP port: `contentAPI.addInputParams()` stores `['shape' => [...]]` bodies. */
    public function testConvertsAShapeBodyFromTheContentApiService(): void
    {
        $route = self::contentAPIRoute([
            'method' => 'POST',
            'request' => ['body' => ['application/json' => ['shape' => [
                'clientMutationId' => z::string()->max(100)->optional(),
                'plain' => static fn (mixed $value): mixed => $value,
            ]]]],
        ]);

        $assembler = new BodyAssembler();
        $context = $this->createOperationContext();
        $assembler->assemble($context, $route);

        $schema = $context->output->data['requestBody']['content']['application/json']['schema'];
        self::assertSame(['type' => 'string', 'maxLength' => 100], $schema['properties']['clientMutationId']);
        self::assertEquals(new \stdClass(), $schema['properties']['plain']);
        self::assertArrayNotHasKey('required', $schema);
    }
}
