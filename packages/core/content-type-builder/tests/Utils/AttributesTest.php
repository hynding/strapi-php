<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Utils\Attributes;

/** Port of server/src/utils/__tests__/attributes.vitest.test.ts. */
final class AttributesTest extends TestCase
{
    public function testIsConfigurable(): void
    {
        self::assertTrue(Attributes::isConfigurable(['type' => 'string']));
        self::assertFalse(Attributes::isConfigurable(['type' => 'string', 'configurable' => false]));
    }

    public function testIsRelation(): void
    {
        self::assertTrue(Attributes::isRelation(['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'api::article.article']));
        self::assertFalse(Attributes::isRelation(['type' => 'string']));
    }

    public function testFormatsMediaAttributes(): void
    {
        // upstream's `configurable: undefined` is left out
        self::assertSame([
            'type' => 'media',
            'multiple' => true,
            'required' => true,
            'private' => true,
            'allowedTypes' => ['images'],
            'pluginOptions' => ['i18n' => ['localized' => true]],
        ], Attributes::formatAttribute([
            'type' => 'media',
            'multiple' => true,
            'required' => true,
            'private' => true,
            'allowedTypes' => ['images'],
            'pluginOptions' => ['i18n' => ['localized' => true]],
        ]));
    }

    public function testFormatsRelationAttributesWithTargetAttributeFromInversedBy(): void
    {
        $formatted = Attributes::formatAttribute([
            'type' => 'relation',
            'relation' => 'oneToMany',
            'target' => 'api::article.article',
            'inversedBy' => 'author',
            'private' => false,
            'configurable' => false,
        ]);

        self::assertSame('relation', $formatted['type']);
        self::assertSame('api::article.article', $formatted['target']);
        self::assertSame('author', $formatted['targetAttribute']);
        self::assertFalse($formatted['configurable']);
        self::assertFalse($formatted['private']);
        self::assertFalse($formatted['required']);
    }

    public function testPreservesRequiredOnRelationAttributes(): void
    {
        $formatted = Attributes::formatAttribute(['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::author.author', 'required' => true]);

        self::assertSame('api::author.author', $formatted['target']);
        self::assertTrue($formatted['required']);
    }

    public function testReturnsOtherAttributeTypesUnchanged(): void
    {
        $attribute = ['type' => 'string', 'minLength' => 3];
        self::assertSame($attribute, Attributes::formatAttribute($attribute));
    }
}
