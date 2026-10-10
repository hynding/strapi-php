<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\Async;
use Strapi\Utils\ContentApiConstants;
use Strapi\Utils\ContentApiRouteParams;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\ModelCache;
use Strapi\Utils\Policy;
use Strapi\Utils\PrintValue;
use Strapi\Utils\PublicationFilter;
use Strapi\Utils\Relations;
use Strapi\Utils\SetCreatorFields;
use Strapi\Utils\Template;
use Strapi\Utils\Zod\Z as z;

/** Ports of the small upstream suites: async, model-cache, publication-filter, content-api-constants, policy, template... */
final class MiscTest extends TestCase
{
    public function testAsyncPipeMapReduce(): void
    {
        $pipe = Async::pipe(static fn (int $a, int $b): int => $a + $b, static fn (int $x): int => $x * 2);
        self::assertSame(6, $pipe(1, 2));
        self::assertSame('x', Async::pipe()('x'));
        self::assertSame([2, 4], Async::map([1, 2], static fn (int $v, int $i): int => $v * 2));
        self::assertSame(6, Async::reduce([1, 2, 3])(static fn (int $acc, int $v): int => $acc + $v, 0));
    }

    public function testModelCache(): void
    {
        $calls = 0;
        $cache = ModelCache::createModelCache(static function (string $uid) use (&$calls): array {
            $calls++;

            return ['uid' => $uid];
        });

        self::assertSame(['uid' => 'a'], $cache->getModel('a'));
        self::assertSame(['uid' => 'a'], $cache->getModel('a'));
        self::assertSame(1, $calls);
        $cache->clear();
        $cache->getModel('a');
        self::assertSame(2, $calls);
        self::assertSame(['uid' => 'b'], ($cache->callable())('b'));
    }

    public function testPublicationFilter(): void
    {
        self::assertNull(PublicationFilter::parsePublicationFilter(null));
        self::assertSame('modified', PublicationFilter::parsePublicationFilter('modified'));

        try {
            PublicationFilter::validatePublicationFilterQueryParam('nope');
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertStringStartsWith("Invalid value for 'publicationFilter'. Expected one of: never-published", $e->getMessage());
            self::assertSame(['source' => 'query', 'param' => 'publicationFilter'], $e->details);
        }
    }

    public function testContentApiConstants(): void
    {
        foreach (['filters', 'sort', 'fields', 'populate', 'locale', 'status'] as $key) {
            self::assertContains($key, ContentApiConstants::SHARED_QUERY_PARAM_KEYS);
        }
        foreach ([...ContentApiConstants::SHARED_QUERY_PARAM_KEYS, 'pagination', 'count', 'ordering'] as $key) {
            self::assertContains($key, ContentApiConstants::ALLOWED_QUERY_PARAM_KEYS);
        }
        self::assertSame(['id', 'documentId'], ContentApiConstants::RESERVED_INPUT_PARAM_KEYS);
    }

    public function testContentApiRouteParams(): void
    {
        $route = ['request' => ['query' => ['filters' => null, 'search' => 'string'], 'body' => ['application/json' => ['shape' => ['data' => null, 'clientMutationId' => null]]]]];
        self::assertSame(['search'], ContentApiRouteParams::getExtraQueryKeysFromRoute($route));
        self::assertSame(['data', 'clientMutationId'], ContentApiRouteParams::getExtraRootKeysFromRouteBody($route));
        self::assertSame([], ContentApiRouteParams::getExtraQueryKeysFromRoute(null));
        self::assertSame([true, 'x'], ContentApiRouteParams::runValidator(null, 'x'));
        self::assertSame([false, 'bad'], ContentApiRouteParams::runValidator(static fn (): never => throw new \RuntimeException('bad'), 'x'));

        // a Zod schema: safeParse, keeping its output (transforms, defaults)
        self::assertSame([true, 'bar'], ContentApiRouteParams::runValidator(z::string()->trim(), '  bar  '));
        self::assertSame([true, ['enabled' => true]], ContentApiRouteParams::runValidator(z::object(['enabled' => z::boolean()->default(true)]), []));
        [$ok] = ContentApiRouteParams::runValidator(z::number(), 'not-a-number');
        self::assertFalse($ok);
    }

    public function testPolicy(): void
    {
        $policy = Policy::createPolicy(['name' => 'is-authenticated', 'validator' => static function (mixed $config): void {
            if (!is_array($config)) {
                throw new \RuntimeException('nope');
            }
        }, 'handler' => static fn (): bool => true]);

        self::assertSame('is-authenticated', $policy->name);
        self::assertTrue($policy->handle());
        $policy->validate([]);

        try {
            $policy->validate('x');
            self::fail('expected throw');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid config passed to "is-authenticated" policy.', $e->getMessage());
        }

        $ctx = Policy::createPolicyContext('koa', ['state' => ['user' => 1]]);
        self::assertSame('koa', $ctx->type);
        self::assertTrue($ctx->is('koa'));
        self::assertFalse($ctx->is('graphql'));
        self::assertSame(['user' => 1], $ctx->state);
        self::assertSame(['user' => 1], $ctx['state']);
        self::assertSame('unnamed', Policy::createPolicy(['handler' => static fn () => null])->name);
    }

    public function testSetCreatorFields(): void
    {
        $user = ['id' => 7];
        self::assertSame(['title' => 'x', 'createdBy' => 7, 'updatedBy' => 7], SetCreatorFields::create(['user' => $user])(['title' => 'x']));
        self::assertSame(['title' => 'x', 'updatedBy' => 7], SetCreatorFields::apply(['title' => 'x'], ['user' => $user, 'isEdition' => true]));
    }

    public function testTemplate(): void
    {
        $strict = Template::createStrictInterpolationRegExp(['URL', 'CODE']);
        self::assertSame(1, preg_match($strict, '<%= URL %>'));
        self::assertSame(1, preg_match($strict, '<%=CODE%>'));
        self::assertSame(0, preg_match($strict, '<%= OTHER %>'));
        self::assertSame(1, preg_match(Template::createLooseInterpolationRegExp(), '<%= OTHER %>'));
        self::assertSame('go to http://x/reset?code=42 <%= OTHER %>', Template::render('go to <%= URL %>?code=<%=CODE%> <%= OTHER %>', ['URL' => 'http://x/reset', 'CODE' => 42]));
    }

    public function testPrintValue(): void
    {
        self::assertSame('null', PrintValue::printValue(null));
        self::assertSame('true', PrintValue::printValue(true));
        self::assertSame('NaN', PrintValue::printValue(NAN));
        self::assertSame('12', PrintValue::printValue(12.0));
        self::assertSame('abc', PrintValue::printValue('abc'));
        self::assertSame('"abc"', PrintValue::printValue('abc', true));
        self::assertSame('2020-01-02T03:04:05.000Z', PrintValue::printValue(new \DateTimeImmutable('2020-01-02T03:04:05Z')));
        self::assertSame("{\n    \"a\": 1\n}", PrintValue::printValue(['a' => 1]));
        self::assertSame('[Exception: boom]', PrintValue::printValue(new \Exception('boom')));
    }

    public function testRelations(): void
    {
        self::assertTrue(Relations::isOneToAny(['type' => 'relation', 'relation' => 'oneToMany']));
        self::assertTrue(Relations::isManyToAny(['type' => 'relation', 'relation' => 'manyToOne']));
        self::assertTrue(Relations::isAnyToOne(['type' => 'relation', 'relation' => 'manyToOne']));
        self::assertTrue(Relations::isAnyToMany(['type' => 'relation', 'relation' => 'manyToMany']));
        self::assertTrue(Relations::isPolymorphic(['relation' => 'morphToMany']));
        self::assertFalse(Relations::isPolymorphic(['relation' => 'oneToOne']));
        self::assertSame(['rel'], Relations::getRelationalFields(['attributes' => ['rel' => ['type' => 'relation'], 'title' => ['type' => 'string']]]));
        self::assertTrue((Relations::validRelationOrderingKeys()['strict'])(true));
        self::assertFalse((Relations::validRelationOrderingKeys()['strict'])('true'));
    }
}
