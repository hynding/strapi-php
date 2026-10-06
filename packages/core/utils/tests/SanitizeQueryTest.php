<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\AuthScope;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Sanitize\ApiSanitizers;
use Strapi\Utils\Sanitize\Sanitize;

/** Port of __tests__/sanitize-query.test.ts and sanitize-input.test.ts. */
final class SanitizeQueryTest extends TestCase
{
    private ApiSanitizers $sanitizers;

    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        $this->sanitizers = Sanitize::createAPISanitizers(['getModel' => TestFixtures::getModelFn()]);
        $this->schema = TestFixtures::ARTICLE_MODEL;

        // upstream mocks `strapi.contentTypes` and `strapi.auth.verify` (denies admin::user.find)
        AuthScope::setRegisteredContentTypes(static fn (): array => ['api::allowed.allowed', 'admin::user', 'api::other.other', 'plugin::upload.file']);
        AuthScope::setVerifier(static function (mixed $auth, string $scope): bool {
            if ($scope === 'admin::user.find') {
                throw new \RuntimeException('Unauthorized');
            }

            return true;
        });
    }

    protected function tearDown(): void
    {
        AuthScope::setVerifier(null);
        AuthScope::setRegisteredContentTypes(null);
    }

    public function testStripsUnrecognizedKeysWhenStrictParams(): void
    {
        $query = ['filters' => ['id' => 1], 'where' => ['id' => 1]];

        $result = $this->sanitizers->query($query, $this->schema, ['strictParams' => true]);
        self::assertArrayNotHasKey('where', $result);
        self::assertArrayHasKey('filters', $result);

        $result = $this->sanitizers->query($query, $this->schema, ['strictParams' => false]);
        self::assertArrayHasKey('where', $result);
    }

    public function testKeepsExtraParamFromRouteWhenValidatorParses(): void
    {
        $route = ['request' => ['query' => ['search' => static fn (mixed $v): string => is_string($v) ? trim($v) : throw new \InvalidArgumentException('expected string')]]];
        $result = $this->sanitizers->query(['filters' => ['id' => 1], 'search' => '  foo  '], $this->schema, ['strictParams' => true, 'route' => $route]);

        self::assertSame('foo', $result['search']);
        self::assertArrayHasKey('filters', $result);
    }

    public function testOmitsExtraParamWhenValidatorFails(): void
    {
        $route = ['request' => ['query' => ['search' => static fn (mixed $v): string => is_string($v) && $v !== '' ? $v : throw new \InvalidArgumentException('min 1')]]];
        $result = $this->sanitizers->query(['filters' => ['id' => 1], 'search' => ''], $this->schema, ['strictParams' => true, 'route' => $route]);

        self::assertArrayNotHasKey('search', $result);
        self::assertArrayHasKey('filters', $result);
    }

    public function testSanitizesExtraQueryParamThatIsArrayOfScalars(): void
    {
        $route = ['request' => ['query' => ['tags' => static fn (array $arr): array => array_map('trim', $arr)]]];
        $result = $this->sanitizers->query(['filters' => ['id' => 1], 'tags' => ['  a  ', '  b  ']], $this->schema, ['strictParams' => true, 'route' => $route]);

        self::assertSame(['a', 'b'], $result['tags']);
    }

    public function testThrowsValidationErrorWhenPublicationFilterIsInvalid(): void
    {
        try {
            $this->sanitizers->query(['filters' => ['id' => 1], 'publicationFilter' => 'invalid-mode'], $this->schema);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame('query', $e->details['source']);
            self::assertSame('publicationFilter', $e->details['param']);
        }
    }

    public function testPassesThroughValidPublicationFilter(): void
    {
        $result = $this->sanitizers->query(['filters' => ['id' => 1], 'publicationFilter' => 'modified'], $this->schema);
        self::assertSame('modified', $result['publicationFilter']);
    }

    public function testRemovesInvalidNestedScalarFilterKeysNextToOperators(): void
    {
        $result = $this->sanitizers->query(['filters' => ['title' => ['$containsi' => 'foo', '__invalidNestedFilterKey' => 'x']]], $this->schema);

        self::assertSame(['filters' => ['title' => ['$containsi' => 'foo']]], $result);
    }

    /** @return iterable<string, array{mixed}> */
    public static function meaninglessSorts(): iterable
    {
        yield 'empty array (GraphQL default)' => [[]];
        yield 'empty string' => [''];
        yield 'comma-only string' => [','];
        yield 'empty object' => [[]];
        yield 'array of empty strings' => [['']];
        yield 'array with null (qs sort[])' => [[null]];
    }

    #[DataProvider('meaninglessSorts')]
    public function testRemovesMeaninglessSort(mixed $sort): void
    {
        $result = $this->sanitizers->query(['sort' => $sort, 'filters' => ['id' => 1]], $this->schema);

        self::assertArrayNotHasKey('sort', $result);
        self::assertArrayHasKey('filters', $result);
    }

    public function testKeepsMeaningfulSort(): void
    {
        $result = $this->sanitizers->query(['sort' => 'title:asc', 'filters' => ['id' => 1]], $this->schema);
        self::assertSame('title:asc', $result['sort']);
    }

    public function testSanitizesFieldsAndPopulate(): void
    {
        $result = $this->sanitizers->query(['fields' => ['title', 'createdBy', 'nope'], 'populate' => ['createdBy' => ['fields' => ['firstname', 'email']]]], $this->schema);

        self::assertSame(['fields' => ['title'], 'populate' => ['createdBy' => ['fields' => ['firstname']]]], $result);
    }

    public function testPassesAuthToPopulateSanitization(): void
    {
        $result = $this->sanitizers->query(['populate' => ['createdBy' => true]], $this->schema, ['auth' => new \stdClass()]);
        self::assertSame(['populate' => []], $result);
    }

    /** @return array<string, mixed> */
    private function morphSchema(string $relation = 'morphToOne', string $name = 'related'): array
    {
        $schema = $this->schema;
        $schema['attributes'][$name] = ['type' => 'relation', 'relation' => $relation];

        return $schema;
    }

    public function testSanitizesMorphPopulateFragmentsWithAuth(): void
    {
        $result = $this->sanitizers->query(['populate' => ['related' => ['on' => ['admin::user' => true, 'api::article.article' => true]]]], $this->morphSchema(), ['auth' => new \stdClass()]);

        self::assertSame(['populate' => ['related' => ['on' => ['api::article.article' => true]]]], $result);
    }

    public function testPreservesExplicitMorphCountPopulateFragments(): void
    {
        $result = $this->sanitizers->query(['populate' => ['related' => ['count' => true, 'on' => ['admin::user' => true, 'api::article.article' => true]]]], $this->morphSchema(), ['auth' => new \stdClass()]);
        self::assertSame(['populate' => ['related' => ['count' => true, 'on' => ['api::article.article' => true]]]], $result);

        $result = $this->sanitizers->query(['populate' => ['related' => ['count' => 'true', 'on' => ['admin::user' => true, 'api::article.article' => true]]]], $this->morphSchema(), ['auth' => new \stdClass()]);
        self::assertSame(['populate' => ['related' => ['count' => true, 'on' => ['api::article.article' => true]]]], $result);
    }

    /** @return iterable<string, array{string, mixed, array<string, mixed>}> */
    public static function morphAllOrCount(): iterable
    {
        foreach (['morphToOne', 'morphToMany'] as $relation) {
            yield "{$relation} boolean" => [$relation, true, []];
            yield "{$relation} boolean string" => [$relation, 'true', []];
            yield "{$relation} count" => [$relation, ['count' => true], ['count' => true]];
            yield "{$relation} count string" => [$relation, ['count' => 'true'], ['count' => true]];
        }
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('morphAllOrCount')]
    public function testRewritesMorphPopulateIntoAuthorizedOnFragments(string $relation, mixed $populateValue, array $expected): void
    {
        $result = $this->sanitizers->query(['populate' => ['related' => $populateValue]], $this->morphSchema($relation), ['auth' => new \stdClass()]);

        self::assertEquals(['populate' => ['related' => [...$expected, 'on' => ['api::allowed.allowed' => true, 'api::other.other' => true, 'plugin::upload.file' => true]]]], $result);
    }

    public function testRemovesMorphPopulateWhenNoTargetIsAuthorized(): void
    {
        AuthScope::setRegisteredContentTypes(static fn (): array => ['admin::user']);
        $result = $this->sanitizers->query(['populate' => ['deniedRelated' => true]], $this->morphSchema('morphToOne', 'deniedRelated'), ['auth' => new \stdClass()]);

        self::assertSame(['populate' => []], $result);
    }

    public function testOutputRemovesPasswordsAndPrivateFieldsThroughRelations(): void
    {
        $entity = ['id' => 1, 'title' => 'x', 'createdBy' => ['id' => 2, 'firstname' => 'John', 'email' => 'a@b.c', 'password' => 'h']];
        $result = $this->sanitizers->output($entity, $this->schema);

        self::assertSame(['id' => 1, 'title' => 'x', 'createdBy' => ['id' => 2, 'firstname' => 'John']], $result);
        self::assertSame([['id' => 1, 'title' => 'x', 'createdBy' => ['id' => 2, 'firstname' => 'John']]], $this->sanitizers->output([$entity], $this->schema));
        self::assertSame(['id' => 1, 'title' => 'x'], $this->sanitizers->output($entity, $this->schema, ['auth' => new \stdClass()]));
    }

    public function testInputRemovesIdsAndNonWritableAttributes(): void
    {
        $schema = $this->schema;
        $schema['attributes']['createdAt'] = ['type' => 'datetime'];
        $schema['attributes']['locked'] = ['type' => 'string', 'writable' => false];
        $result = $this->sanitizers->input(['id' => 1, 'documentId' => 'x', 'title' => 'a', 'createdAt' => 'now', 'locked' => 'y', 'extra' => 1], $schema);

        self::assertSame(['title' => 'a', 'extra' => 1], $result);
        self::assertSame(['title' => 'a'], $this->sanitizers->input(['title' => 'a', 'extra' => 1], $schema, ['strictParams' => true]));
    }

    public function testInputKeepsExtraParamFromRouteWhenValidatorParses(): void
    {
        $route = ['request' => ['body' => ['application/json' => ['shape' => ['title' => null, 'clientMutationId' => static fn (string $s): string => trim($s)]]]]];
        $result = $this->sanitizers->input(['title' => 'x', 'clientMutationId' => '  foo  '], $this->schema, ['strictParams' => true, 'route' => $route]);

        self::assertSame(['title' => 'x', 'clientMutationId' => 'foo'], $result);
    }

    public function testInputRemovesExtraParamWhenValidatorFails(): void
    {
        $route = ['request' => ['body' => ['application/json' => ['shape' => ['title' => null, 'clientMutationId' => static fn (string $s): string => $s !== '' ? $s : throw new \InvalidArgumentException('min 1')]]]]];
        $result = $this->sanitizers->input(['title' => 'x', 'clientMutationId' => ''], $this->schema, ['strictParams' => true, 'route' => $route]);

        self::assertSame(['title' => 'x'], $result);
    }

    public function testInputStripsRootKeyNotInRouteBodySchemaWhenStrict(): void
    {
        $route = ['request' => ['body' => ['application/json' => ['shape' => ['title' => null, 'clientMutationId' => null]]]]];
        $result = $this->sanitizers->input(['title' => 'x', 'otherExtraKey' => 'y'], $this->schema, ['strictParams' => true, 'route' => $route]);

        self::assertSame(['title' => 'x'], $result);
    }

    public function testInputSanitizesNestedObjectExtraParam(): void
    {
        $route = ['request' => ['body' => ['application/json' => ['shape' => ['title' => null, 'metadata' => static fn (array $m): array => [...$m, 'source' => trim($m['source'])]]]]]];
        $result = $this->sanitizers->input(['title' => 'x', 'metadata' => ['source' => '  web  ', 'version' => 1]], $this->schema, ['strictParams' => true, 'route' => $route]);

        self::assertSame(['title' => 'x', 'metadata' => ['source' => 'web', 'version' => 1]], $result);
    }

    public function testRegisteredSanitizersRun(): void
    {
        $sanitizers = Sanitize::contentAPI(TestFixtures::getModelFn(), [
            'output' => [static fn (array $schema): \Closure => static fn (mixed $data): mixed => [...$data, 'extra' => $schema['uid']]],
        ]);

        self::assertSame(['title' => 'x', 'extra' => 'api::article.article'], $sanitizers->output(['title' => 'x'], $this->schema));
    }

    public function testThrowsWithoutSchema(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Missing schema in sanitizeQuery');
        $this->sanitizers->query([], null);
    }
}
