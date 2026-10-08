<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Services;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\I18n\Services\AiLocalizations;
use Strapi\Plugin\I18n\Tests\I18nTestApp;
use Strapi\Types\Schema\Schema;

/**
 * Port of server/src/services/__tests__/ai-localizations.test.ts (`mergeUnsupportedFields`).
 * The `generateDocumentLocalizations` cases mock the document service, populate builder and
 * provider end to end; they are not ported.
 */
final class AiLocalizationsTest extends TestCase
{
    /** @return array<string, Schema> */
    private static function models(): array
    {
        return [
            'components.media-section' => I18nTestApp::schema('components.media-section', ['attributes' => [
                'sectionTitle' => ['type' => 'string'],
                'sectionDescription' => ['type' => 'text'],
                'sectionImage' => ['type' => 'media', 'multiple' => false],
                'sectionBackground' => ['type' => 'enumeration', 'enum' => ['white', 'gray', 'blue']],
                'isFullWidth' => ['type' => 'boolean'],
            ]], 'component'),
            'components.seo' => I18nTestApp::schema('components.seo', ['attributes' => [
                'metaTitle' => ['type' => 'string'],
                'ogImage' => ['type' => 'media', 'multiple' => false],
            ]], 'component'),
            'api::author.author' => I18nTestApp::schema('api::author.author', ['attributes' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ]]),
            'plugin::upload.file' => I18nTestApp::schema('plugin::upload.file', ['attributes' => [
                'name' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'mime' => ['type' => 'string'],
            ]]),
        ];
    }

    /**
     * @param array<string, mixed> $targetData
     * @param array<string, mixed>|null $sourceDoc
     * @param array<string, array<string, mixed>> $attributes
     * @return array<string, mixed>
     */
    private static function merge(array $targetData, ?array $sourceDoc, array $attributes): array
    {
        $schema = I18nTestApp::schema('api::test.test', ['attributes' => $attributes]);

        return AiLocalizations::mergeUnsupportedFields($targetData, $sourceDoc, $schema, static fn (string $uid): ?Schema => self::models()[$uid] ?? null);
    }

    /** @return array<string, array<string, mixed>> */
    private static function rootAttributes(): array
    {
        return [
            'title' => ['type' => 'string'],
            'content' => ['type' => 'richtext'],
            'featuredImage' => ['type' => 'media', 'multiple' => false],
            'backgroundColor' => ['type' => 'enumeration', 'enum' => ['white', 'gray', 'blue']],
            'isFeatured' => ['type' => 'boolean'],
            'author' => ['type' => 'relation', 'relation' => 'oneToOne'],
        ];
    }

    public function testPreservesMediaFields(): void
    {
        $result = self::merge(
            ['title' => 'Translated Title', 'content' => 'Translated content'],
            ['title' => 'Original Title', 'content' => 'Original content', 'featuredImage' => ['id' => 1, 'url' => '/image.jpg']],
            self::rootAttributes(),
        );

        self::assertSame('Translated Title', $result['title']);
        self::assertSame('Translated content', $result['content']);
        self::assertSame(['id' => 1, 'url' => '/image.jpg'], $result['featuredImage']);
    }

    public function testPreservesBooleanEnumerationAndRelationFields(): void
    {
        $result = self::merge(
            ['title' => 'Translated Title'],
            ['title' => 'Original Title', 'isFeatured' => true, 'backgroundColor' => 'blue', 'author' => ['id' => 5, 'name' => 'John Doe']],
            self::rootAttributes(),
        );

        self::assertSame('Translated Title', $result['title']);
        self::assertTrue($result['isFeatured']);
        self::assertSame('blue', $result['backgroundColor']);
        self::assertSame(['id' => 5, 'name' => 'John Doe'], $result['author']);
    }

    public function testDoesNotOverwriteExistingUnsupportedFieldsInTarget(): void
    {
        $result = self::merge(['title' => 'Translated Title', 'isFeatured' => false], ['title' => 'Original', 'isFeatured' => true], self::rootAttributes());

        self::assertFalse($result['isFeatured']);
    }

    public function testHandlesANullSourceDocument(): void
    {
        self::assertSame(['title' => 'Translated Title'], self::merge(['title' => 'Translated Title'], null, self::rootAttributes()));
    }

    public function testIgnoresSystemFields(): void
    {
        $result = self::merge(['title' => 'Translated Title'], [
            'id' => 1, 'documentId' => 'abc', 'createdAt' => '2024', 'updatedAt' => '2024', 'publishedAt' => '2024',
            'locale' => 'en', 'title' => 'Original', 'isFeatured' => true,
        ], self::rootAttributes());

        self::assertSame(['isFeatured' => true, 'title' => 'Translated Title'], $result);
    }

    public function testPreservesUnsupportedFieldsInASingleComponent(): void
    {
        $attributes = ['title' => ['type' => 'string'], 'seo' => ['type' => 'component', 'component' => 'components.seo', 'repeatable' => false]];
        $result = self::merge(
            ['title' => 'T', 'seo' => ['metaTitle' => 'Translated Meta']],
            ['title' => 'O', 'seo' => ['metaTitle' => 'Original Meta', 'ogImage' => ['id' => 3, 'url' => '/og.jpg']]],
            $attributes,
        );
        self::assertSame(['ogImage' => ['id' => 3, 'url' => '/og.jpg'], 'metaTitle' => 'Translated Meta'], $result['seo']);

        $result = self::merge(['title' => 'T'], ['title' => 'O', 'seo' => ['metaTitle' => 'Original Meta', 'ogImage' => ['id' => 3]]], $attributes);
        self::assertSame(['ogImage' => ['id' => 3]], $result['seo']);
    }

    public function testPreservesUnsupportedFieldsInRepeatableComponents(): void
    {
        $attributes = ['title' => ['type' => 'string'], 'sections' => ['type' => 'component', 'component' => 'components.media-section', 'repeatable' => true]];
        $result = self::merge(
            ['title' => 'Translated Title', 'sections' => [
                ['sectionTitle' => 'Translated Section 1', 'sectionDescription' => 'Desc 1'],
                ['sectionTitle' => 'Translated Section 2', 'sectionDescription' => 'Desc 2'],
            ]],
            ['title' => 'Original Title', 'sections' => [
                ['sectionTitle' => 'Original Section 1', 'sectionDescription' => 'Desc 1', 'sectionImage' => ['id' => 1, 'url' => '/img1.jpg'], 'sectionBackground' => 'blue', 'isFullWidth' => true],
                ['sectionTitle' => 'Original Section 2', 'sectionDescription' => 'Desc 2', 'sectionImage' => ['id' => 2, 'url' => '/img2.jpg'], 'sectionBackground' => 'gray', 'isFullWidth' => false],
            ]],
            $attributes,
        );

        self::assertCount(2, $result['sections']);
        self::assertSame('Translated Section 1', $result['sections'][0]['sectionTitle']);
        self::assertSame(['id' => 1, 'url' => '/img1.jpg'], $result['sections'][0]['sectionImage']);
        self::assertSame('blue', $result['sections'][0]['sectionBackground']);
        self::assertTrue($result['sections'][0]['isFullWidth']);
        self::assertSame('Translated Section 2', $result['sections'][1]['sectionTitle']);
        self::assertSame('gray', $result['sections'][1]['sectionBackground']);
        self::assertFalse($result['sections'][1]['isFullWidth']);

        // only unsupported fields are preserved when the target has no such component
        $result = self::merge(['title' => 'T'], ['title' => 'O', 'sections' => [['sectionTitle' => 'Section 1', 'sectionImage' => ['id' => 1, 'url' => '/img1.jpg']]]], $attributes);
        self::assertSame([['sectionImage' => ['id' => 1, 'url' => '/img1.jpg']]], $result['sections']);

        // mismatched array lengths
        $result = self::merge(
            ['title' => 'T', 'sections' => [['sectionTitle' => 'Translated Section 1'], ['sectionTitle' => 'Translated Section 2'], ['sectionTitle' => 'Translated Section 3']]],
            ['title' => 'O', 'sections' => [['sectionTitle' => 'Section 1', 'sectionImage' => ['id' => 1]], ['sectionTitle' => 'Section 2', 'sectionImage' => ['id' => 2]]]],
            $attributes,
        );
        self::assertCount(3, $result['sections']);
        self::assertSame(['id' => 1], $result['sections'][0]['sectionImage']);
        self::assertSame(['id' => 2], $result['sections'][1]['sectionImage']);
        self::assertSame(['sectionTitle' => 'Translated Section 3'], $result['sections'][2]);
    }

    public function testPreservesAllInternalFieldsOfRelationAndMediaObjects(): void
    {
        $result = self::merge(
            ['title' => 'Translated Title'],
            [
                'title' => 'Original Title',
                'author' => ['id' => 5, 'name' => 'John Doe', 'email' => 'john@example.com'],
                'cover' => ['id' => 10, 'name' => 'cover.jpg', 'url' => '/uploads/cover.jpg', 'mime' => 'image/jpeg'],
            ],
            [
                'title' => ['type' => 'string'],
                'author' => ['type' => 'relation', 'relation' => 'manyToOne', 'target' => 'api::author.author'],
                'cover' => ['type' => 'media', 'multiple' => false],
            ],
        );

        self::assertSame(['id' => 5, 'name' => 'John Doe', 'email' => 'john@example.com'], $result['author']);
        self::assertSame(['id' => 10, 'name' => 'cover.jpg', 'url' => '/uploads/cover.jpg', 'mime' => 'image/jpeg'], $result['cover']);
    }

    public function testPreservesUnsupportedFieldsInDynamicZoneComponents(): void
    {
        $result = self::merge(
            ['title' => 'Translated Title', 'blocks' => [
                ['__component' => 'components.media-section', 'sectionTitle' => 'Translated Section'],
                ['__component' => 'components.seo', 'metaTitle' => 'Translated Meta'],
            ]],
            ['title' => 'Original Title', 'blocks' => [
                ['__component' => 'components.media-section', 'sectionTitle' => 'Original Section', 'sectionImage' => ['id' => 1, 'url' => '/img.jpg'], 'sectionBackground' => 'blue'],
                ['__component' => 'components.seo', 'metaTitle' => 'Original Meta', 'ogImage' => ['id' => 2, 'url' => '/og.jpg']],
            ]],
            ['title' => ['type' => 'string'], 'blocks' => ['type' => 'dynamiczone', 'components' => ['components.media-section', 'components.seo']]],
        );

        self::assertSame('Translated Section', $result['blocks'][0]['sectionTitle']);
        self::assertSame(['id' => 1, 'url' => '/img.jpg'], $result['blocks'][0]['sectionImage']);
        self::assertSame('blue', $result['blocks'][0]['sectionBackground']);
        self::assertSame('Translated Meta', $result['blocks'][1]['metaTitle']);
        self::assertSame(['id' => 2, 'url' => '/og.jpg'], $result['blocks'][1]['ogImage']);
    }
}
