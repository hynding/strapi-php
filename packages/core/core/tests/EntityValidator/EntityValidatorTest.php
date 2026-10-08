<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\EntityValidator;

use Strapi\Core\Tests\BootedAppTestCase;
use Strapi\Utils\EmptyObject;
use Strapi\Utils\Errors\YupValidationError;

/** Port of packages/core/core/src/services/entity-validator/__tests__/index.test.ts. */
final class EntityValidatorTest extends BootedAppTestCase
{
    private const MODEL_BASE = [
        'modelType' => 'contentType',
        'uid' => 'api::test.test',
        'kind' => 'collectionType',
        'modelName' => 'test',
        'globalId' => 'test',
        'info' => ['displayName' => 'Test', 'singularName' => 'test', 'pluralName' => 'tests'],
        'options' => [],
        'attributes' => [],
    ];

    /** @param array<string, array<string, mixed>> $attributes */
    private static function model(array $attributes): array
    {
        return [...self::MODEL_BASE, 'attributes' => $attributes];
    }

    /** @return array{name: string, message: string, details: array{errors: list<array<string, mixed>>}} */
    private static function catchValidation(callable $fn): array
    {
        try {
            $fn();
        } catch (YupValidationError $e) {
            return ['name' => $e->name, 'message' => $e->getMessage(), 'details' => $e->details];
        }

        self::fail('Expected a ValidationError');
    }

    public function testPublishedThrowsOnInvalidInput(): void
    {
        $model = self::model(['title' => ['type' => 'string']]);

        $error = self::catchValidation(fn () => self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => 1234]));

        self::assertSame('ValidationError', $error['name']);
        self::assertSame('title must be a `string` type, but the final value was: `1234`.', $error['message']);
        self::assertSame(['title'], $error['details']['errors'][0]['path']);
        self::assertSame('title must be a `string` type, but the final value was: `1234`.', $error['details']['errors'][0]['message']);
        self::assertSame('ValidationError', $error['details']['errors'][0]['name']);
    }

    public function testPublishedReturnsDataOnValidInput(): void
    {
        $model = self::model(['title' => ['type' => 'string']]);
        $input = ['title' => 'test Title'];

        self::assertSame($input, self::strapi()->entityValidator()->validateEntityCreation($model, $input));
    }

    public function testPublishedReturnsCastedDataWhenPossible(): void
    {
        $model = self::model(['title' => ['type' => 'string'], 'number' => ['type' => 'integer']]);

        self::assertSame(['title' => 'Test', 'number' => 123], self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => 'Test', 'number' => '123']));
    }

    public function testPublishedThrowsOnRequiredNotRespected(): void
    {
        $model = self::model(['title' => ['type' => 'string', 'required' => true]]);

        $error = self::catchValidation(fn () => self::strapi()->entityValidator()->validateEntityCreation($model, []));
        self::assertSame('title must be defined.', $error['message']);
        self::assertSame([['path' => ['title'], 'message' => 'title must be defined.', 'name' => 'ValidationError']], array_map(static fn (array $e): array => array_diff_key($e, ['value' => 1]), $error['details']['errors']));

        $error = self::catchValidation(fn () => self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => null]));
        self::assertSame('title must be a `string` type, but the final value was: `null`.', $error['message']);
        self::assertSame(['title'], $error['details']['errors'][0]['path']);
    }

    public function testSupportsCustomFieldTypes(): void
    {
        $model = self::model(['uuid' => ['type' => 'uuid']]);
        $input = ['uuid' => '2479d6d7-2497-478d-8a34-a9e8ce45f8a7'];

        self::assertSame($input, self::strapi()->entityValidator()->validateEntityCreation($model, $input));
    }

    public function testStringMinLength(): void
    {
        $model = self::model(['title' => ['type' => 'string', 'minLength' => 10]]);

        $error = self::catchValidation(fn () => self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => 'tooSmall']));
        self::assertSame('title must be at least 10 characters', $error['message']);
        self::assertSame(['title'], $error['details']['errors'][0]['path']);
        self::assertSame('title must be at least 10 characters', $error['details']['errors'][0]['message']);
    }

    public function testStringMaxLength(): void
    {
        $model = self::model(['title' => ['type' => 'string', 'maxLength' => 2]]);

        $error = self::catchValidation(fn () => self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => 'tooLong']));
        self::assertSame('title must be at most 2 characters', $error['message']);
    }

    public function testAllowsEmptyStringsEvenWhenRequired(): void
    {
        $model = self::model(['title' => ['type' => 'string', 'required' => true]]);

        self::assertSame(['title' => ''], self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => '']));
    }

    public function testAssignsDefaultValues(): void
    {
        $model = self::model([
            'title' => ['type' => 'string', 'required' => true, 'default' => 'New'],
            'type' => ['type' => 'string', 'default' => 'test'],
            'testDate' => ['type' => 'date', 'required' => true, 'default' => '2020-04-01T04:00:00.000Z'],
            'testJSON' => ['type' => 'json', 'required' => true, 'default' => ['foo' => 1, 'bar' => 2]],
        ]);

        $data = self::strapi()->entityValidator()->validateEntityCreation($model, []);

        self::assertSame('New', $data['title']);
        self::assertSame('test', $data['type']);
        self::assertSame('2020-04-01T04:00:00.000Z', $data['testDate']);
        self::assertSame(['foo' => 1, 'bar' => 2], $data['testJSON']);
    }

    public function testDoesNotAssignDefaultValueIfEmptyString(): void
    {
        $model = self::model([
            'title' => ['type' => 'string', 'required' => true, 'default' => 'default'],
            'content' => ['type' => 'string', 'default' => 'default'],
        ]);

        $data = self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => '', 'content' => '']);

        self::assertSame(['title' => '', 'content' => ''], $data);
    }

    public function testDraftThrowsOnInvalidInput(): void
    {
        $model = self::model(['title' => ['type' => 'string']]);

        $error = self::catchValidation(fn () => self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => 1234], ['isDraft' => true]));
        self::assertSame('title must be a `string` type, but the final value was: `1234`.', $error['message']);
    }

    public function testDraftDoesNotThrowOnRequiredNotRespected(): void
    {
        $model = self::model(['title' => ['type' => 'string', 'required' => true]]);

        self::assertSame([], self::strapi()->entityValidator()->validateEntityCreation($model, [], ['isDraft' => true]));
        self::assertSame(['title' => null], self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => null], ['isDraft' => true]));
    }

    public function testDraftDoesNotThrowOnMinLengthNotRespected(): void
    {
        $model = self::model(['title' => ['type' => 'string', 'minLength' => 10]]);

        self::assertSame(['title' => 'tooSmall'], self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => 'tooSmall'], ['isDraft' => true]));
    }

    public function testDraftThrowsOnMaxLengthNotRespected(): void
    {
        $model = self::model(['title' => ['type' => 'string', 'maxLength' => 2]]);

        $error = self::catchValidation(fn () => self::strapi()->entityValidator()->validateEntityCreation($model, ['title' => 'tooLong'], ['isDraft' => true]));
        self::assertSame('title must be at most 2 characters', $error['message']);
    }

    public function testDraftAssignsDefaultValues(): void
    {
        $model = self::model([
            'title' => ['type' => 'string', 'required' => true, 'default' => 'New'],
            'type' => ['type' => 'string', 'default' => 'test'],
            'testDate' => ['type' => 'date', 'required' => true, 'default' => '2020-04-01T04:00:00.000Z'],
            'testJSON' => ['type' => 'json', 'required' => true, 'default' => ['foo' => 1, 'bar' => 2]],
        ]);

        $data = self::strapi()->entityValidator()->validateEntityCreation($model, [], ['isDraft' => true]);

        self::assertSame('New', $data['title']);
        self::assertSame('test', $data['type']);
        self::assertSame('2020-04-01T04:00:00.000Z', $data['testDate']);
        self::assertSame(['foo' => 1, 'bar' => 2], $data['testJSON']);
    }

    public function testInvalidPayloadShape(): void
    {
        $model = self::model(['title' => ['type' => 'string']]);

        $this->expectException(\Strapi\Utils\Errors\ValidationError::class);
        $this->expectExceptionMessage('Invalid payload submitted for the creation of an entity of type Test. Expected an object, but got string');
        self::strapi()->entityValidator()->validateEntityCreation($model, 'nope');
    }

    public function testUpdateKeepsMissingRequiredAttributes(): void
    {
        $model = self::model(['title' => ['type' => 'string', 'required' => true]]);

        self::assertSame(['other' => 1], array_intersect_key(self::strapi()->entityValidator()->validateEntityUpdate($model, ['other' => 1]), ['other' => 1]));

        $error = self::catchValidation(fn () => self::strapi()->entityValidator()->validateEntityUpdate($model, ['title' => null]));
        self::assertSame('title must be a `string` type, but the final value was: `null`.', $error['message']);
    }

    public function testEnumerationEmailAndIntegerValidators(): void
    {
        $model = self::model([
            'status' => ['type' => 'enumeration', 'enum' => ['a', 'b']],
            'email' => ['type' => 'email'],
            'count' => ['type' => 'integer', 'min' => 1, 'max' => 5],
        ]);
        $validator = self::strapi()->entityValidator();

        self::assertSame(['status' => 'a', 'email' => 'a@b.co', 'count' => 3], $validator->validateEntityCreation($model, ['status' => 'a', 'email' => 'a@b.co', 'count' => 3]));

        $error = self::catchValidation(fn () => $validator->validateEntityCreation($model, ['status' => 'c']));
        self::assertSame('status must be one of the following values: a, b, null', $error['message']);

        $error = self::catchValidation(fn () => $validator->validateEntityCreation($model, ['email' => 'nope']));
        self::assertSame('email must be a valid email', $error['message']);

        $error = self::catchValidation(fn () => $validator->validateEntityCreation($model, ['count' => 9]));
        self::assertSame('count must be less than or equal to 5', $error['message']);
    }

    /**
     * PHP port: request bodies keep a JSON `{}` apart from `[]` (Strapi\Utils\EmptyObject), so the
     * component checks of upstream's yup schemas apply: a single component sent as `[]`, or a
     * repeatable component / dynamic zone sent as `{}`, is a type error.
     */
    public function testComponentsTellAnEmptyObjectFromAnEmptyArray(): void
    {
        $model = self::model([
            'single' => ['type' => 'component', 'component' => 'default.dish', 'repeatable' => false],
            'many' => ['type' => 'component', 'component' => 'default.dish', 'repeatable' => true],
            'dz' => ['type' => 'dynamiczone', 'components' => ['default.dish']],
            'json' => ['type' => 'json'],
        ]);
        $validator = self::strapi()->entityValidator();

        // `{}` is a single component (cast with its defaults); `[]` a repeatable one or a dynamic zone
        $valid = $validator->validateEntityCreation($model, ['single' => new EmptyObject(), 'many' => [], 'dz' => [], 'json' => new EmptyObject()]);
        self::assertSame(['name' => 'My super dish'], $valid['single']);
        self::assertSame([], $valid['many']);
        self::assertSame([], $valid['dz']);
        self::assertInstanceOf(EmptyObject::class, $valid['json'], 'a json `{}` is stored (and answered) as `{}`');

        $error = self::catchValidation(fn () => $validator->validateEntityCreation($model, ['single' => []]));
        self::assertSame('single must be a `object` type, but the final value was: `[]`.', $error['message']);

        $error = self::catchValidation(fn () => $validator->validateEntityCreation($model, ['many' => new EmptyObject()]));
        self::assertSame('many must be a `array` type, but the final value was: `{}`.', $error['message']);

        $error = self::catchValidation(fn () => $validator->validateEntityUpdate($model, ['dz' => new EmptyObject()], null, ['id' => 1]));
        self::assertSame('dz must be a `array` type, but the final value was: `{}`.', $error['message']);

        $error = self::catchValidation(fn () => $validator->validateEntityCreation($model, ['many' => [[]]]));
        // array items are cast (no preventCast): yup's object transform turns `[]` into null
        self::assertSame('many[0] must be a `object` type, but the final value was: `null` (cast from the value `[]`).', $error['message']);

        // a component item sent as `{}` is an object (with its defaults)
        self::assertSame([['name' => 'My super dish']], $validator->validateEntityCreation($model, ['many' => [new EmptyObject()]])['many']);
    }
}
