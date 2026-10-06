<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\AuthScope;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Validate\ApiValidators;
use Strapi\Utils\Validate\Validate;

/** Port of __tests__/validate-query.test.ts and validate-input.test.ts. */
final class ValidateQueryTest extends TestCase
{
    private ApiValidators $validators;

    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        $this->validators = Validate::createAPIValidators(['getModel' => TestFixtures::getModelFn()]);
        $this->schema = TestFixtures::ARTICLE_MODEL;
        AuthScope::setVerifier(null);
    }

    public function testThrowsWhenStrictParamsAndUnrecognizedTopLevelKey(): void
    {
        try {
            $this->validators->query(['filters' => ['id' => 1], 'where' => ['id' => 1]], $this->schema, ['strictParams' => true]);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame('Invalid key where', $e->getMessage());
            self::assertSame('query', $e->details['source']);
            self::assertSame('where', $e->details['param']);
        }
    }

    public function testDoesNotThrowForUnrecognizedTopLevelKeyWhenNotStrict(): void
    {
        $this->validators->query(['filters' => ['id' => 1], 'where' => ['id' => 1]], $this->schema, ['strictParams' => false]);
        self::assertTrue(true);
    }

    public function testDoesNotThrowWhenStrictAndOnlyAllowedKeys(): void
    {
        $this->validators->query(['filters' => ['id' => 1], 'sort' => ['title'], 'page' => 1, 'pageSize' => 10], $this->schema, ['strictParams' => true]);
        self::assertTrue(true);
    }

    public function testAllowsExtraQueryParamFromRouteWhenValidatorParses(): void
    {
        $route = ['request' => ['query' => ['search' => static fn (mixed $v): string => is_string($v) ? $v : throw new \InvalidArgumentException('expected string')]]];
        $this->validators->query(['filters' => ['id' => 1], 'search' => 'foo'], $this->schema, ['strictParams' => true, 'route' => $route]);
        self::assertTrue(true);
    }

    public function testThrowsWhenExtraParamPresentButValidatorFails(): void
    {
        $route = ['request' => ['query' => ['search' => static fn (mixed $v): string => is_string($v) ? $v : throw new \InvalidArgumentException('expected string')]]];

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('expected string');
        $this->validators->query(['filters' => ['id' => 1], 'search' => 123], $this->schema, ['strictParams' => true, 'route' => $route]);
    }

    public function testValidatesExtraQueryParamThatIsArrayOfScalars(): void
    {
        $validator = static fn (mixed $v): array => is_array($v) ? $v : throw new \InvalidArgumentException('expected array');
        $route = ['request' => ['query' => ['tags' => $validator]]];

        $this->validators->query(['filters' => ['id' => 1], 'tags' => ['a', 'b']], $this->schema, ['strictParams' => true, 'route' => $route]);

        $this->expectException(ValidationError::class);
        $this->validators->query(['filters' => ['id' => 1], 'tags' => 'not-an-array'], $this->schema, ['strictParams' => true, 'route' => $route]);
    }

    public function testThrowsValidationErrorWithDetailsWhenPublicationFilterIsInvalid(): void
    {
        try {
            $this->validators->query(['filters' => ['id' => 1], 'publicationFilter' => 'not-a-valid-mode'], $this->schema);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame('query', $e->details['source']);
            self::assertSame('publicationFilter', $e->details['param']);
        }
    }

    public function testDoesNotThrowWhenPublicationFilterIsValidOrOmitted(): void
    {
        $this->validators->query(['filters' => ['id' => 1], 'publicationFilter' => 'never-published'], $this->schema);
        $this->validators->query(['filters' => ['id' => 1]], $this->schema);
        self::assertTrue(true);
    }

    /** @return iterable<string, array{mixed}> */
    public static function morphPopulates(): iterable
    {
        yield 'boolean populate' => [true];
        yield 'count populate' => [['count' => true]];
    }

    #[DataProvider('morphPopulates')]
    public function testAcceptsMorphPopulateAuthorizationForSanitizerHandling(mixed $populateValue): void
    {
        $schema = $this->schema;
        $schema['attributes']['related'] = ['type' => 'relation', 'relation' => 'morphToOne'];

        $this->validators->query(['populate' => ['related' => $populateValue]], $schema, ['auth' => new \stdClass()]);
        self::assertTrue(true);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidQueries(): iterable
    {
        yield 'private filter' => [['filters' => ['createdBy' => ['email' => 'x']]], 'filters'];
        yield 'unknown filter key' => [['filters' => ['nope' => 'x']], 'filters'];
        yield 'private sort' => [['sort' => 'createdBy.email:asc'], 'sort'];
        yield 'relation in fields' => [['fields' => ['title', 'createdBy']], 'fields'];
        yield 'scalar populate' => [['populate' => ['title' => true]], 'populate'];
        yield 'unknown populate' => [['populate' => 'nope'], 'populate'];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidQueries')]
    public function testQueryThrowsWithParamDetails(array $query, string $param): void
    {
        try {
            $this->validators->query($query, $this->schema);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame('query', $e->details['source']);
            self::assertSame($param, $e->details['param']);
        }
    }

    public function testWildcardPopulateIsAlwaysValid(): void
    {
        $this->validators->query(['populate' => '*', 'fields' => 'title', 'sort' => ['title:asc'], 'filters' => [['title' => 'a'], ['id' => 1]]], $this->schema);
        self::assertTrue(true);
    }

    public function testRestrictedRelationsWithAuth(): void
    {
        AuthScope::setVerifier(static fn (mixed $auth, string $scope): bool => $scope !== 'admin::user.find');

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key createdBy');
        $this->validators->filters(['createdBy' => ['firstname' => 'x']], $this->schema, ['auth' => new \stdClass()]);
    }

    // ---------------------------------------------------------------------------------------------
    // input
    // ---------------------------------------------------------------------------------------------

    public function testInputThrowsForUnrecognizedRootLevelKey(): void
    {
        try {
            $this->validators->input(['title' => 'x', 'extraKey' => 'y'], $this->schema);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame('Invalid key extraKey', $e->getMessage());
            self::assertSame('body', $e->details['source']);
        }
    }

    public function testInputRejectsIdsAndNonWritableAttributes(): void
    {
        $schema = $this->schema;
        $schema['attributes']['createdAt'] = ['type' => 'datetime'];

        try {
            $this->validators->input(['id' => 1, 'title' => 'x'], $schema);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame('Invalid key id', $e->getMessage());
        }

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key createdAt');
        $this->validators->input(['title' => 'x', 'createdAt' => 'now'], $schema);
    }

    public function testInputAcceptsOnlySchemaAttributesAtRoot(): void
    {
        $this->validators->input(['title' => 'x'], $this->schema);
        $this->validators->input([['title' => 'x'], ['title' => 'y']], $this->schema);
        self::assertTrue(true);
    }

    public function testInputAllowsExtraParamFromRouteWhenValidatorParses(): void
    {
        $route = ['request' => ['body' => ['application/json' => ['shape' => ['title' => null, 'clientMutationId' => static fn (mixed $v): string => is_string($v) ? $v : throw new \InvalidArgumentException('expected string')]]]]];
        $this->validators->input(['title' => 'x', 'clientMutationId' => 'abc'], $this->schema, ['route' => $route]);
        self::assertTrue(true);
    }

    public function testInputThrowsWhenExtraParamPresentButValidatorFails(): void
    {
        $route = ['request' => ['body' => ['application/json' => ['shape' => ['title' => null, 'clientMutationId' => static fn (mixed $v): string => is_string($v) ? $v : throw new \InvalidArgumentException('expected string')]]]]];

        try {
            $this->validators->input(['title' => 'x', 'clientMutationId' => 123], $this->schema, ['route' => $route]);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame('expected string', $e->getMessage());
            self::assertSame('body', $e->details['source']);
            self::assertSame('clientMutationId', $e->details['param']);
        }
    }

    public function testInputExtraRootKeyNotInRouteBodySchemaStillThrows(): void
    {
        $route = ['request' => ['body' => ['application/json' => ['shape' => ['title' => null, 'clientMutationId' => null]]]]];

        $this->expectException(ValidationError::class);
        $this->validators->input(['title' => 'x', 'otherExtraKey' => 'y'], $this->schema, ['route' => $route]);
    }

    public function testInputAllowsNestedObjectExtraParam(): void
    {
        $validator = static fn (mixed $m): array => is_array($m) && is_int($m['version'] ?? null) ? $m : throw new \InvalidArgumentException('version must be a number');
        $route = ['request' => ['body' => ['application/json' => ['shape' => ['title' => null, 'metadata' => $validator]]]]];

        $this->validators->input(['title' => 'x', 'metadata' => ['source' => 'web', 'version' => 1]], $this->schema, ['route' => $route]);

        $this->expectException(ValidationError::class);
        $this->validators->input(['title' => 'x', 'metadata' => ['source' => 'web', 'version' => 'not-a-number']], $this->schema, ['route' => $route]);
    }
}
