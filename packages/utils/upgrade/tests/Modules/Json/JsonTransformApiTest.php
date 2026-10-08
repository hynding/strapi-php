<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Json;

use Strapi\Upgrade\Modules\Json\JSONTransformAPI;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/json/__tests__/json-transform-api.test.ts. */
final class JsonTransformApiTest extends TestCase
{
    private const MODEL = ['foo' => 'bar', 'nested' => ['bar' => 42]];

    private JSONTransformAPI $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = JSONTransformAPI::createJSONTransformAPI(self::MODEL);
    }

    public function testModificationsMadeOnTheBaseObjectAreIgnored(): void
    {
        $obj = new \stdClass();
        $obj->a = 1;
        $api = JSONTransformAPI::createJSONTransformAPI(['obj' => $obj]);
        $obj->another = 'property';

        self::assertEquals(['obj' => (object) ['a' => 1]], $api->root());
    }

    public function testGet(): void
    {
        self::assertNull($this->api->get('unknown-path'));
        self::assertSame(42, $this->api->get('unknown-path', 42));
        self::assertSame('bar', $this->api->get('foo'));
        self::assertSame(self::MODEL, $this->api->get(''));
        self::assertSame(42, $this->api->get('nested.bar'));
    }

    public function testHas(): void
    {
        self::assertFalse($this->api->has('unknown-path'));
        self::assertTrue($this->api->has('foo'));
        self::assertTrue($this->api->has('nested.bar'));
    }

    public function testSet(): void
    {
        self::assertSame([...self::MODEL, 'foo' => 'baz'], (clone $this->api)->set('foo', 'baz')->root());
        self::assertSame([...self::MODEL, 'bar' => 'baz'], (clone $this->api)->set('bar', 'baz')->root());
        self::assertSame(['foo' => 'bar', 'nested' => ['bar' => 42, 'newProp' => 1]], (clone $this->api)->set('nested.newProp', 1)->root());
    }

    public function testMergeOperatesADeepMerge(): void
    {
        $this->api->merge(['baz' => 'foo', 'nested' => ['newProp' => 84]]);

        self::assertEquals(['foo' => 'bar', 'baz' => 'foo', 'nested' => ['bar' => 42, 'newProp' => 84]], $this->api->root());
    }

    public function testRemoveAndLodashPaths(): void
    {
        $api = JSONTransformAPI::createJSONTransformAPI(['dependencies' => ['@strapi/plugin-i18n' => '4.0.0', 'react-dom' => '18']]);

        self::assertTrue($api->has('dependencies.@strapi/plugin-i18n'));
        self::assertSame('18', $api->get('dependencies["react-dom"]'));
        self::assertSame(['dependencies' => ['react-dom' => '18']], $api->remove('dependencies.@strapi/plugin-i18n')->root());
        self::assertSame(['a', 'b', '0', 'c.d'], JSONTransformAPI::toPath('a.b[0]["c.d"]'));
    }

    public function testIsEqual(): void
    {
        self::assertTrue(JSONTransformAPI::isEqual(['a' => 1, 'b' => [1, 2]], ['b' => [1, 2], 'a' => 1.0]));
        self::assertFalse(JSONTransformAPI::isEqual(['b' => [1, 2]], ['b' => [2, 1]]));
        self::assertFalse(JSONTransformAPI::isEqual(['a' => '1'], ['a' => 1]));
    }
}
