<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Controllers\Validation;

require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Controllers\Validation\ModelConfiguration;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;
use Strapi\Utils\Yup\YupError;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/__tests__/model-configuration.test.ts. */
final class ModelConfigurationTest extends TestCase
{
    private const array MOCK_SCHEMA = [
        'attributes' => [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'text'],
            'published' => ['type' => 'boolean'],
            'category' => [
                'type' => 'relation',
                'relation' => 'manyToOne',
                'target' => 'api::category.category',
                'targetModel' => 'api::category.category',
            ],
        ],
    ];

    private Strapi $strapi;

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
        StubStrapi::setService($this->strapi, 'content-types', new class () {
            /** @return array<string, mixed> */
            public function findContentType(string $uid): array
            {
                return ['attributes' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'description' => ['type' => 'text']]];
            }
        });
    }

    private function schema(): YupObject
    {
        return ModelConfiguration::createModelConfigurationSchema($this->strapi, self::MOCK_SCHEMA);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<mixed>
     */
    private function validate(array $config): array
    {
        $result = $this->schema()->validate($config);
        self::assertIsArray($result);

        return $result;
    }

    /** @param array<string, mixed> $config */
    private function assertRejects(array $config): void
    {
        try {
            $this->schema()->validate($config);
            self::fail('validation should have failed');
        } catch (YupError) {
            $this->addToAssertionCount(1);
        }
    }

    public function testShouldValidateACompleteValidConfiguration(): void
    {
        $this->validate([
            'settings' => [
                'bulkable' => true,
                'filterable' => true,
                'pageSize' => 10,
                'searchable' => true,
                'mainField' => 'title',
                'defaultSortBy' => 'id',
                'defaultSortOrder' => 'ASC',
            ],
            'metadatas' => [
                'title' => [
                    'edit' => ['label' => 'Title', 'description' => 'Article title', 'placeholder' => 'Enter title here', 'editable' => true, 'visible' => true],
                    'list' => ['label' => 'Title', 'searchable' => true, 'sortable' => true],
                ],
            ],
            'layouts' => ['edit' => [[['name' => 'title', 'size' => 6]]], 'list' => ['title']],
            'options' => [],
        ]);
        $this->addToAssertionCount(1);
    }

    public function testShouldAllowNullValuesForOptionalRootProperties(): void
    {
        $this->validate(['settings' => null, 'metadatas' => null, 'layouts' => null, 'options' => []]);
        $this->addToAssertionCount(1);
    }

    public function testShouldRejectUnknownProperties(): void
    {
        $this->assertRejects(['settings' => [], 'metadatas' => [], 'layouts' => [], 'options' => [], 'unknownProperty' => 'should not be allowed']);
    }

    public function testShouldAcceptNullValuesForDescriptionAndPlaceholder(): void
    {
        $result = $this->validate(['metadatas' => ['title' => ['edit' => [
            'label' => 'Title Field', 'description' => null, 'placeholder' => null, 'editable' => true, 'visible' => true,
        ]]]]);

        self::assertNull($result['metadatas']['title']['edit']['description']);
        self::assertNull($result['metadatas']['title']['edit']['placeholder']);
    }

    public function testShouldAcceptEmptyStringsForDescriptionAndPlaceholder(): void
    {
        $result = $this->validate(['metadatas' => ['title' => ['edit' => [
            'label' => 'Title Field', 'description' => '', 'placeholder' => '', 'editable' => true, 'visible' => true,
        ]]]]);

        self::assertSame('', $result['metadatas']['title']['edit']['description']);
        self::assertSame('', $result['metadatas']['title']['edit']['placeholder']);
    }

    public function testShouldAcceptUndefinedValuesForDescriptionAndPlaceholder(): void
    {
        $result = $this->validate(['metadatas' => ['title' => ['edit' => ['label' => 'Title Field', 'editable' => true, 'visible' => true]]]]);

        self::assertArrayNotHasKey('description', $result['metadatas']['title']['edit']);
        self::assertArrayNotHasKey('placeholder', $result['metadatas']['title']['edit']);
    }

    public function testShouldConvertNonStringValuesForDescriptionToStrings(): void
    {
        $result = $this->validate(['metadatas' => ['title' => ['edit' => [
            'label' => 'Title Field', 'description' => 123, 'placeholder' => 'Valid placeholder', 'editable' => true, 'visible' => true,
        ]]]]);

        self::assertSame('123', $result['metadatas']['title']['edit']['description']);
    }

    public function testShouldRejectNonStringValuesForPlaceholder(): void
    {
        $this->assertRejects(['metadatas' => ['title' => ['edit' => [
            'label' => 'Title Field', 'description' => 'Valid description', 'placeholder' => ['invalid', 'array'], 'editable' => true, 'visible' => true,
        ]]]]);
    }

    public function testShouldConvertBooleanValuesForDescriptionAndPlaceholderToStrings(): void
    {
        $result1 = $this->validate(['metadatas' => ['title' => ['edit' => [
            'label' => 'Title Field', 'description' => true, 'placeholder' => 'Valid placeholder', 'editable' => true, 'visible' => true,
        ]]]]);
        $result2 = $this->validate(['metadatas' => ['title' => ['edit' => [
            'label' => 'Title Field', 'description' => 'Valid description', 'placeholder' => false, 'editable' => true, 'visible' => true,
        ]]]]);

        self::assertSame('true', $result1['metadatas']['title']['edit']['description']);
        self::assertSame('false', $result2['metadatas']['title']['edit']['placeholder']);
    }

    public function testShouldAcceptValidListMetadata(): void
    {
        $this->validate(['metadatas' => ['title' => ['list' => ['label' => 'Title', 'searchable' => true, 'sortable' => true]]]]);
        $this->addToAssertionCount(1);
    }

    public function testShouldValidateMultipleAttributesWithNullableDescriptionAndPlaceholder(): void
    {
        $result = $this->validate(['metadatas' => [
            'title' => ['edit' => ['label' => 'Title', 'description' => null, 'placeholder' => 'Enter title', 'editable' => true, 'visible' => true]],
            'description' => ['edit' => ['label' => 'Description', 'description' => 'Long description field', 'placeholder' => null, 'editable' => true, 'visible' => true]],
            'published' => ['edit' => ['label' => 'Published', 'description' => null, 'placeholder' => null, 'editable' => true, 'visible' => true]],
        ]]);

        self::assertNull($result['metadatas']['title']['edit']['description']);
        self::assertSame('Enter title', $result['metadatas']['title']['edit']['placeholder']);
        self::assertSame('Long description field', $result['metadatas']['description']['edit']['description']);
        self::assertNull($result['metadatas']['description']['edit']['placeholder']);
        self::assertNull($result['metadatas']['published']['edit']['description']);
        self::assertNull($result['metadatas']['published']['edit']['placeholder']);
    }

    public function testShouldValidateRequiredSettingsFields(): void
    {
        $this->validate(['settings' => [
            'bulkable' => true, 'filterable' => true, 'pageSize' => 25, 'searchable' => false,
            'mainField' => 'title', 'defaultSortBy' => 'id', 'defaultSortOrder' => 'DESC',
        ]]);
        $this->addToAssertionCount(1);
    }

    public function testShouldEnforcePageSizeLimits(): void
    {
        $this->assertRejects(['settings' => ['bulkable' => true, 'filterable' => true, 'pageSize' => 5, 'searchable' => true]]);
        $this->assertRejects(['settings' => ['bulkable' => true, 'filterable' => true, 'pageSize' => 150, 'searchable' => true]]);
    }

    public function testShouldAcceptValidEditLayouts(): void
    {
        $this->validate(['layouts' => [
            'edit' => [[['name' => 'title', 'size' => 6], ['name' => 'published', 'size' => 6]], [['name' => 'description', 'size' => 12]]],
            'list' => ['title', 'published'],
        ]]);
        $this->addToAssertionCount(1);
    }

    public function testShouldHandleEmptyMetadatasObject(): void
    {
        $this->validate(['metadatas' => []]);
        $this->addToAssertionCount(1);
    }

    public function testShouldHandlePartialEditMetadata(): void
    {
        $this->validate(['metadatas' => ['title' => ['edit' => ['label' => 'Title']]]]);
        $this->addToAssertionCount(1);
    }

    public function testShouldMaintainOriginalBehaviorForNonNullableFields(): void
    {
        // This should fail because label is required and shouldn't be null
        $this->assertRejects(['metadatas' => ['title' => ['edit' => [
            'label' => null, 'description' => null, 'placeholder' => null, 'editable' => true, 'visible' => true,
        ]]]]);
    }

    public function testShouldNotBreakExistingConfigurationsWithoutDescriptionOrPlaceholder(): void
    {
        $this->validate(['metadatas' => ['title' => [
            'edit' => ['label' => 'Title', 'editable' => true, 'visible' => true],
            'list' => ['label' => 'Title', 'searchable' => true, 'sortable' => true],
        ]]]);
        $this->addToAssertionCount(1);
    }

    public function testShouldMaintainCompatibilityWithExistingStringValues(): void
    {
        $result = $this->validate(['metadatas' => ['title' => ['edit' => [
            'label' => 'Title', 'description' => 'Existing description', 'placeholder' => 'Existing placeholder', 'editable' => true, 'visible' => true,
        ]]]]);

        self::assertSame('Existing description', $result['metadatas']['title']['edit']['description']);
        self::assertSame('Existing placeholder', $result['metadatas']['title']['edit']['placeholder']);
    }

    public function testListLayoutMustBeAnArray(): void
    {
        $this->assertRejects(['layouts' => ['edit' => [], 'list' => 'title']]);
    }
}
