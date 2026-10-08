<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Controllers\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Controllers\Validation\Types;
use Strapi\Utils\Yup\YupError;

/** Port of server/src/controllers/validation/__tests__/types.test.ts. */
final class TypesTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $attributes
     * @param list<string> $types
     */
    private static function isValid(array $attributes, string $key, array $types): bool
    {
        return Types::getTypeValidator($attributes[$key], ['types' => $types, 'attributes' => $attributes])->isValidSync($attributes[$key]);
    }

    /**
     * @param array<string, array<string, mixed>> $attributes
     * @param list<string> $types
     */
    private static function errorOf(array $attributes, string $key, array $types): string
    {
        try {
            Types::getTypeValidator($attributes[$key], ['types' => $types, 'attributes' => $attributes])->validateSync($attributes[$key]);
        } catch (YupError $error) {
            return $error->getMessage();
        }

        return '';
    }

    public function testPluginOptionsCanBeUsed(): void
    {
        $attributes = ['title' => ['type' => 'string', 'pluginOptions' => ['i18n' => ['localized' => false]]]];
        self::assertTrue(self::isValid($attributes, 'title', ['string']));
    }

    public function testCanUseCustomKeys(): void
    {
        $attributes = ['title' => ['type' => 'string', 'myCustomKey' => true]];
        self::assertTrue(self::isValid($attributes, 'title', ['string']));
    }

    public function testStringMaxLength(): void
    {
        self::assertFalse(self::isValid(['title' => ['type' => 'string', 'maxLength' => 256]], 'title', ['string']));
        self::assertTrue(self::isValid(['title' => ['type' => 'string', 'maxLength' => 255]], 'title', ['string']));
        self::assertTrue(self::isValid(['title' => ['type' => 'string', 'maxLength' => 100]], 'title', ['string']));
    }

    public function testDynamiczoneComponents(): void
    {
        self::assertFalse(self::isValid(['dz' => ['type' => 'dynamiczone', 'components' => []]], 'dz', ['dynamiczone']));
        self::assertTrue(self::isValid(['dz' => ['type' => 'dynamiczone', 'components' => ['default.compoA', 'default.compoB']]], 'dz', ['dynamiczone']));
    }

    public function testUidTargetField(): void
    {
        self::assertTrue(self::isValid(['slug' => ['type' => 'uid']], 'slug', ['uid']));
        self::assertFalse(self::isValid(['slug' => ['type' => 'uid', 'targetField' => 'unknown']], 'slug', ['uid']));
        self::assertTrue(self::isValid(['title' => ['type' => 'string'], 'slug' => ['type' => 'uid', 'targetField' => 'title']], 'slug', ['uid']));
        self::assertTrue(self::isValid(['title' => ['type' => 'text'], 'slug' => ['type' => 'uid', 'targetField' => 'title']], 'slug', ['uid']));
        self::assertFalse(self::isValid([
            'relation' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::foo.foo'],
            'slug' => ['type' => 'uid', 'targetField' => 'relation'],
        ], 'slug', ['uid']));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUidTargetTypes(): iterable
    {
        foreach (['media', 'richtext', 'json', 'enumeration', 'password', 'email', 'integer', 'biginteger', 'float', 'decimal', 'date', 'time', 'datetime', 'timestamp', 'boolean'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('invalidUidTargetTypes')]
    public function testUidTargetFieldCannotBe(string $type): void
    {
        self::assertFalse(self::isValid(['title' => ['type' => $type], 'slug' => ['type' => 'uid', 'targetField' => 'title']], 'slug', ['uid']));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function uidDefaults(): iterable
    {
        yield ['basic-uid-value1911', true];
        yield ['uid_with_underscore', true];
        yield ['tilde~_isAllowed', true];
        yield ['dots.are.allowed', true];
        yield ['no-accentsé', false];
        yield ['no-special$-chars&@', false];
        yield ['some-invalid&é&e-uid', false];
        yield ['some/azdazd/', false];
        yield ['some(azdazd)', false];
        yield ['some=azdazd', false];
        yield ['some?azdazd=azdaz', false];
    }

    #[DataProvider('uidDefaults')]
    public function testUidDefaultValueMustMatchRegex(string $value, bool $isValid): void
    {
        self::assertSame($isValid, self::isValid(['slug' => ['type' => 'uid', 'default' => $value]], 'slug', ['uid']));
    }

    public function testUidDefaultMatchesACustomRegex(): void
    {
        self::assertTrue(self::isValid(['slug' => ['type' => 'uid', 'default' => 'some/value', 'regex' => '^[A-Za-z0-9-_.~/]*$']], 'slug', ['uid']));
    }

    public function testUidDefaultShouldNotBeDefinedWithATargetField(): void
    {
        self::assertStringContainsString(
            'cannot define a default UID if the targetField is set',
            self::errorOf(['title' => ['type' => 'string'], 'slug' => ['type' => 'uid', 'targetField' => 'title', 'default' => 'some-value']], 'slug', ['uid']),
        );
    }

    public function testUidMinAndMaxLength(): void
    {
        self::assertStringContainsString(
            'maxLength must be greater or equal to minLength',
            self::errorOf(['slug' => ['type' => 'uid', 'minLength' => 120, 'maxLength' => 119]], 'slug', ['uid']),
        );
        self::assertTrue(self::isValid(['slug' => ['type' => 'uid', 'minLength' => 120, 'maxLength' => 120]], 'slug', ['uid']));
        self::assertFalse(self::isValid(['slug' => ['type' => 'uid', 'maxLength' => 257]], 'slug', ['uid']));
    }

    public function testMediaAllowedTypes(): void
    {
        self::assertFalse(self::isValid(['img' => ['type' => 'media', 'allowedTypes' => ['nonexistent']]], 'img', ['media']));
        self::assertFalse(self::isValid(['img' => ['type' => 'media', 'allowedTypes' => ['all', 'videos']]], 'img', ['media']));
        self::assertTrue(self::isValid(['img' => ['type' => 'media', 'allowedTypes' => ['files', 'videos']]], 'img', ['media']));
        foreach (['audios', 'images', 'files', 'videos'] as $type) {
            self::assertTrue(self::isValid(['img' => ['type' => 'media', 'allowedTypes' => [$type]]], 'img', ['media']), $type);
        }
    }

    public function testRelationAcceptsRequired(): void
    {
        self::assertTrue(self::isValid(['author' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::author.author', 'required' => true]], 'author', ['relation']));
    }
}
