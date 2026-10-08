<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\Validation\Utilities;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodNumber;
use Strapi\Utils\Zod\ZodReadonly;

/** Port of packages/core/utils/src/__tests__/validation-utilities-schema.vitest.test.ts. */
final class ValidationUtilitiesSchemaTest extends TestCase
{
    // --- maybeRequired ---

    public function testMakesSchemaOptionalByDefault(): void
    {
        $schema = Utilities::maybeRequired()(z::string());
        self::assertTrue($schema->safeParse()['success']);
    }

    public function testKeepsSchemaRequiredWhenRequiredIsTrue(): void
    {
        $schema = Utilities::maybeRequired(true)(z::string());
        self::assertFalse($schema->safeParse()['success']);
    }

    // --- maybeReadonly ---

    public function testLeavesSchemaWritableByDefault(): void
    {
        $schema = Utilities::maybeReadonly()(z::number());
        self::assertInstanceOf(ZodNumber::class, $schema);
        self::assertSame(1, $schema->parse(1));
    }

    public function testMakesSchemaReadonlyWhenWritableIsFalse(): void
    {
        // upstream asserts Object.isFrozen(result); PHP arrays are values: the schema is readonly
        $schema = Utilities::maybeReadonly(false)(z::object(['count' => z::number()]));

        self::assertInstanceOf(ZodReadonly::class, $schema);
        self::assertSame(['count' => 1], $schema->parse(['count' => 1]));
        self::assertTrue($schema->toJSONSchema()['readOnly']);
    }

    // --- maybeWithDefault ---

    public function testReturnsSchemaUnchangedWhenDefaultIsUndefined(): void
    {
        $schema = Utilities::maybeWithDefault()(z::string());
        self::assertFalse($schema->safeParse()['success']);
    }

    public function testAppliesStaticDefaultValues(): void
    {
        $schema = Utilities::maybeWithDefault('draft')(z::string());
        self::assertSame('draft', $schema->parse());
    }

    public function testAppliesFunctionDefaultValues(): void
    {
        $schema = Utilities::maybeWithDefault(static fn (): int => 42)(z::number());
        self::assertSame(42, $schema->parse());
    }

    // --- maybeWithMinMax ---

    public function testAppliesMinAndMaxWhenBothProvided(): void
    {
        $schema = Utilities::maybeWithMinMax(2, 5)(z::string());
        self::assertFalse($schema->safeParse('a')['success']);
        self::assertTrue($schema->safeParse('abc')['success']);
        self::assertFalse($schema->safeParse('abcdef')['success']);
    }

    public function testReturnsSchemaUnchangedWhenMinOrMaxMissing(): void
    {
        $schema = Utilities::maybeWithMinMax(max: 5)(z::string());
        self::assertTrue($schema->safeParse('a')['success']);
    }

    // --- augmentSchema ---

    public function testAppliesModifiersInOrder(): void
    {
        $schema = Utilities::augmentSchema(z::string(), [
            Utilities::maybeRequired(false),
            Utilities::maybeWithDefault('fallback'),
        ]);

        self::assertSame('fallback', $schema->parse());
    }

    // --- transformUidToValidOpenApiName (PHP-port addition) ---

    public function testTransformsUidsToOpenApiComponentNames(): void
    {
        self::assertSame('BasicSeoEntry', Utilities::transformUidToValidOpenApiName('basic.seo'));
        self::assertSame('ApiCategoryCategoryDocument', Utilities::transformUidToValidOpenApiName('api::category.category'));
        self::assertSame('PluginUploadFileDocument', Utilities::transformUidToValidOpenApiName('plugin::upload.file'));
        self::assertSame('PluginUsersPermissionsUserDocument', Utilities::transformUidToValidOpenApiName('plugin::users-permissions.user'));
        self::assertSame('DefaultSocialLinksEntry', Utilities::transformUidToValidOpenApiName('default.social_links'));
        self::assertSame('FooSchema', Utilities::transformUidToValidOpenApiName('foo'));
    }
}
