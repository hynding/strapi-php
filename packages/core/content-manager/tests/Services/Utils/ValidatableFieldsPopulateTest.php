<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services\Utils;

require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Utils\Populate;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of server/src/services/utils/__tests__/validatable-fields-populate.test.ts. */
final class ValidatableFieldsPopulateTest extends TestCase
{
    private Strapi $strapi;

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
        StubStrapi::addContentTypes($this->strapi, [
            'empty' => ['attributes' => []],
            'scalarOnly' => ['attributes' => [
                'title' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'text', 'required' => false],
            ]],
            'componentWithRequiredFields' => ['attributes' => ['componentAttrName' => ['type' => 'component', 'component' => 'componentFields']]],
            'componentFields' => ['attributes' => [
                'subfield1' => ['type' => 'string', 'required' => true],
                'subfield2' => ['type' => 'number', 'required' => false],
            ]],
            'componentWithoutRequiredFields' => ['attributes' => ['componentAttrName' => ['type' => 'component', 'component' => 'empty']]],
            'media' => ['attributes' => ['mediaAttrName' => ['required' => true, 'type' => 'media']]],
            'scalarWithPrivateRequired' => ['attributes' => [
                'privateRequiredField' => ['type' => 'string', 'required' => true, 'private' => true],
                'publicRequiredField' => ['type' => 'string', 'required' => true],
            ]],
            'mediaWithPrivateRequired' => ['attributes' => [
                'privateMedia' => ['type' => 'media', 'required' => true, 'private' => true],
                'publicMedia' => ['type' => 'media', 'required' => true],
            ]],
            'componentWithPrivateRequiredFields' => ['attributes' => ['componentAttrName' => ['type' => 'component', 'component' => 'componentWithPrivateFields']]],
            'componentWithPrivateFields' => ['attributes' => [
                'privateRequiredField' => ['type' => 'string', 'required' => true, 'private' => true],
                'publicRequiredField' => ['type' => 'string', 'required' => true],
                'privateOptionalField' => ['type' => 'string', 'required' => false, 'private' => true],
            ]],
            'nestedComponent' => ['attributes' => ['nestedComponentAttr' => ['type' => 'component', 'component' => 'componentFields']]],
            'parentModel' => ['attributes' => ['parentComponent' => ['type' => 'component', 'component' => 'nestedComponent']]],
            'dynamicZone' => ['attributes' => ['dynZoneAttrName' => [
                'type' => 'dynamiczone',
                'components' => ['componentFields', 'componentWithRequiredFields', 'componentWithoutRequiredFields'],
            ]]],
            'dynamicZoneWithPrivateComponent' => ['attributes' => ['dynZoneAttrName' => [
                'type' => 'dynamiczone',
                'components' => ['componentWithPrivateFields'],
            ]]],
        ]);
    }

    public function testWithEmptyModel(): void
    {
        self::assertSame([], Populate::getPopulateForValidation($this->strapi, 'empty'));
    }

    public function testWithScalarOnlyModel(): void
    {
        // Only scalar fields requiring validation
        self::assertSame(['fields' => ['title']], Populate::getPopulateForValidation($this->strapi, 'scalarOnly'));
    }

    public function testExcludesTopLevelPrivateRequiredScalarFields(): void
    {
        self::assertSame(['fields' => ['publicRequiredField']], Populate::getPopulateForValidation($this->strapi, 'scalarWithPrivateRequired'));
    }

    public function testWithMediaModel(): void
    {
        self::assertSame(
            ['populate' => ['mediaAttrName' => ['populate' => ['folder' => true]]]],
            Populate::getPopulateForValidation($this->strapi, 'media'),
        );
    }

    public function testExcludesPrivateRequiredMediaFields(): void
    {
        self::assertSame(
            ['populate' => ['publicMedia' => ['populate' => ['folder' => true]]]],
            Populate::getPopulateForValidation($this->strapi, 'mediaWithPrivateRequired'),
        );
    }

    public function testWithComponentModelContainingRequiredFields(): void
    {
        self::assertSame(
            ['populate' => ['componentAttrName' => ['fields' => ['subfield1']]]],
            Populate::getPopulateForValidation($this->strapi, 'componentWithRequiredFields'),
        );
    }

    public function testWithComponentModelWithoutRequiredFields(): void
    {
        // No required fields, so no populate
        self::assertSame([], Populate::getPopulateForValidation($this->strapi, 'componentWithoutRequiredFields'));
    }

    public function testWithComponentModelContainingPrivateRequiredFields(): void
    {
        self::assertSame(
            ['populate' => ['componentAttrName' => ['fields' => ['publicRequiredField']]]],
            Populate::getPopulateForValidation($this->strapi, 'componentWithPrivateRequiredFields'),
        );
    }

    public function testWithNestedComponents(): void
    {
        self::assertSame(
            ['populate' => ['parentComponent' => ['populate' => ['nestedComponentAttr' => ['fields' => ['subfield1']]]]]],
            Populate::getPopulateForValidation($this->strapi, 'parentModel'),
        );
    }

    public function testWithDynamicZoneModel(): void
    {
        self::assertSame([
            'populate' => [
                'dynZoneAttrName' => [
                    'on' => [
                        'componentFields' => ['fields' => ['subfield1']],
                        'componentWithRequiredFields' => ['populate' => ['componentAttrName' => ['fields' => ['subfield1']]]],
                    ],
                ],
            ],
        ], Populate::getPopulateForValidation($this->strapi, 'dynamicZone'));
    }

    public function testExcludesPrivateRequiredFieldsFromDynamicZoneComponents(): void
    {
        self::assertSame(
            ['populate' => ['dynZoneAttrName' => ['on' => ['componentWithPrivateFields' => ['fields' => ['publicRequiredField']]]]]],
            Populate::getPopulateForValidation($this->strapi, 'dynamicZoneWithPrivateComponent'),
        );
    }
}
