<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\EmptyObject;
use Strapi\Utils\PrintValue;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Yup\Yup;
use Strapi\Utils\Yup\YupError;
use Strapi\Utils\Zod\Z;
use Strapi\Utils\Zod\ZodError;

/** PHP port only: a JSON `{}` kept apart from `[]` (src/empty-object.php). */
final class EmptyObjectTest extends TestCase
{
    public function testDecodeKeepsEmptyObjectsApartFromEmptyArrays(): void
    {
        $decoded = EmptyObject::decode('{"a":{},"b":[],"c":{"d":{}},"e":[{}],"f":{"g":1},"h":[1,2]}');

        self::assertIsArray($decoded);
        self::assertInstanceOf(EmptyObject::class, $decoded['a']);
        self::assertSame([], $decoded['b']);
        self::assertInstanceOf(EmptyObject::class, $decoded['c']['d']);
        self::assertInstanceOf(EmptyObject::class, $decoded['e'][0]);
        self::assertSame(['g' => 1], $decoded['f']);
        self::assertSame([1, 2], $decoded['h']);
        self::assertInstanceOf(EmptyObject::class, EmptyObject::decode('{}'));
        self::assertInstanceOf(EmptyObject::class, EmptyObject::decode(' { } '));
    }

    public function testDecodeMatchesJsonDecodeOtherwise(): void
    {
        foreach (['[]', '{"a":[1,{"b":"{}"}]}', '"x"', '12', 'null', '{"0":"a","1":"b"}', '{"":1}', '{"a":{"b":{"c":[{"d":null}]}}}'] as $json) {
            self::assertSame(json_decode($json, true), EmptyObject::toArrays(EmptyObject::decode($json)), $json);
        }
        // a `{}` inside a string takes the slow path, with the same result
        self::assertSame(['a' => '{}'], EmptyObject::decode('{"a":"{}"}'));
    }

    public function testDecodeThrowsWithJsonThrowOnError(): void
    {
        $this->expectException(\JsonException::class);
        EmptyObject::decode('{"a":{}', 512, JSON_THROW_ON_ERROR);
    }

    public function testSerializesBackAsAnEmptyObject(): void
    {
        self::assertSame('{}', json_encode(new EmptyObject()));
        self::assertSame('{"a":{},"b":[],"c":[{}]}', json_encode(EmptyObject::decode('{"a":{},"b":[],"c":[{}]}')));
    }

    public function testReadsLikeAnEmptyArray(): void
    {
        $value = new EmptyObject();

        self::assertNull($value['missing'] ?? null);
        self::assertFalse(isset($value['missing']));
        self::assertCount(0, $value);
        self::assertSame([], iterator_to_array($value));
        self::assertSame([], (array) $value);
        self::assertTrue((bool) $value, 'JS-truthy, as `{}`');
    }

    public function testIsImmutable(): void
    {
        $value = new EmptyObject();

        $this->expectException(\LogicException::class);
        $value['a'] = 1;
    }

    public function testToArraysReplacesEveryMarker(): void
    {
        self::assertSame([], EmptyObject::toArrays(new EmptyObject()));
        self::assertSame(['a' => [], 'b' => ['c' => []], 'd' => [[]]], EmptyObject::toArrays(EmptyObject::decode('{"a":{},"b":{"c":{}},"d":[{}]}')));
        self::assertSame('x', EmptyObject::toArrays('x'));

        $plain = ['a' => ['b' => [1, 2]], 'c' => []];
        self::assertSame($plain, EmptyObject::toArrays($plain));
        self::assertFalse(EmptyObject::contains($plain));
        self::assertTrue(EmptyObject::contains(['a' => [['b' => new EmptyObject()]]]));
    }

    public function testKeepInDocumentDataKeepsMarkersInComponentsAndJsonOnly(): void
    {
        $models = [
            'api::shop.shop' => ['attributes' => [
                'compo' => ['type' => 'component', 'component' => 'default.compo', 'repeatable' => false],
                'compos' => ['type' => 'component', 'component' => 'default.compo', 'repeatable' => true],
                'dz' => ['type' => 'dynamiczone', 'components' => ['default.compo']],
                'meta' => ['type' => 'json'],
                'products' => ['type' => 'relation', 'relation' => 'manyToMany', 'target' => 'api::product.product'],
            ]],
            'default.compo' => ['attributes' => [
                'inner' => ['type' => 'component', 'component' => 'default.compo', 'repeatable' => false],
                'products' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'api::product.product'],
            ]],
        ];
        $getModel = static fn (string $uid): ?array => $models[$uid] ?? null;
        $data = EmptyObject::decode('{"compo":{"inner":{},"products":{"options":{},"connect":[]}},"compos":{},"dz":[{},{"__component":"default.compo","inner":{}}],"meta":{"a":{}},"products":{"options":{},"connect":[]},"other":{}}');

        $kept = EmptyObject::keepInDocumentData($data, $models['api::shop.shop'], $getModel);

        self::assertIsArray($kept);
        self::assertInstanceOf(EmptyObject::class, $kept['compo']['inner']);
        self::assertSame(['options' => [], 'connect' => []], $kept['compo']['products'], 'relation payloads are read as arrays');
        self::assertInstanceOf(EmptyObject::class, $kept['compos']);
        self::assertInstanceOf(EmptyObject::class, $kept['dz'][0]);
        self::assertInstanceOf(EmptyObject::class, $kept['dz'][1]['inner']);
        self::assertInstanceOf(EmptyObject::class, $kept['meta']['a']);
        self::assertSame(['options' => [], 'connect' => []], $kept['products']);
        self::assertSame([], $kept['other']);
    }

    public function testIsObject(): void
    {
        self::assertTrue(EmptyObject::isObject(new EmptyObject()));
        self::assertTrue(EmptyObject::isObject(['a' => 1]));
        self::assertTrue(EmptyObject::isObject([]));
        self::assertFalse(EmptyObject::isObject([], false));
        self::assertFalse(EmptyObject::isObject([1]));
        self::assertTrue(Objects::isPlainObject(new EmptyObject()));
    }

    public function testPrintsAsAnEmptyObject(): void
    {
        self::assertSame('{}', PrintValue::printValue(new EmptyObject()));
        self::assertSame('{}', Yup::print(new EmptyObject()));
    }

    public function testYupObjectAcceptsAndValidatesAnEmptyObject(): void
    {
        $schema = Yup::object(['name' => Yup::string()->required()]);

        try {
            $schema->validate(new EmptyObject(), ['abortEarly' => false]);
            self::fail('expected a YupError');
        } catch (YupError $e) {
            self::assertSame('name is a required field', $e->getMessage());
        }

        // the cast keeps the marker unless it adds keys
        self::assertInstanceOf(EmptyObject::class, Yup::object(['name' => Yup::string()])->validate(new EmptyObject()));
        self::assertSame(['name' => 'x'], Yup::object(['name' => Yup::string()->default('x')])->validate(new EmptyObject()));
    }

    public function testYupObjectRejectEmptyList(): void
    {
        $schema = Yup::object(['name' => Yup::string()->required()])->rejectEmptyList();
        // preventCast, as the entity validator does: the value is checked as sent
        $schema = $schema->transform(static fn (mixed $value, mixed $original): mixed => $original);

        try {
            $schema->validate([], ['abortEarly' => false]);
            self::fail('expected a YupError');
        } catch (YupError $e) {
            self::assertSame('this must be a `object` type, but the final value was: `[]`.', $e->getMessage());
            self::assertCount(1, $e->errors, 'no nested validation once the type check failed');
        }

        self::assertTrue(Yup::object()->isValid([]), '`[]` is still an object by default');
        self::assertTrue(Yup::object()->rejectEmptyList()->isValid(new EmptyObject()));
    }

    public function testYupArrayRejectsAnEmptyObject(): void
    {
        try {
            Yup::array()->of(Yup::object())->strict()->validate(new EmptyObject());
            self::fail('expected a YupError');
        } catch (YupError $e) {
            self::assertSame('this must be a `array` type, but the final value was: `{}`.', $e->getMessage());
        }

        self::assertTrue(Yup::array()->isValid([]));
    }

    public function testZod(): void
    {
        self::assertInstanceOf(EmptyObject::class, Z::object([])->parse(new EmptyObject()));
        self::assertInstanceOf(EmptyObject::class, Z::record(Z::string(), Z::string())->parse(new EmptyObject()));
        self::assertSame(['a' => 'x'], Z::object(['a' => Z::string()->default('x')])->parse(new EmptyObject()));

        try {
            Z::array(Z::string())->parse(new EmptyObject());
            self::fail('expected a ZodError');
        } catch (ZodError $e) {
            self::assertSame('Invalid input: expected array, received object', $e->issues[0]['message']);
        }
    }
}
