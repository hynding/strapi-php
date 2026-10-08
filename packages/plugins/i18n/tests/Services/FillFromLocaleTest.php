<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Services;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Plugin\I18n\Services\FillFromLocale;
use Strapi\Plugin\I18n\Tests\I18nTestApp;

/**
 * Port of server/src/services/__tests__/fill-from-locale.test.ts: field filtering, components,
 * dynamic zones, temp keys and the relation cases that resolve without a database (morph,
 * missing target, unreadable target, empty values). The locale resolution / status / label cases
 * run against the database in tests/api (plugins/i18n/content-manager/fill-from-locale).
 */
final class FillFromLocaleTest extends TestCase
{
    private const string MODEL = 'api::article.article';

    private Strapi $strapi;

    protected function setUp(): void
    {
        $this->strapi = I18nTestApp::create();
    }

    /**
     * @param array<string, array<string, mixed>> $attributes
     * @param array<string, array<string, array<string, mixed>>> $components
     */
    private function service(array $attributes, array $components = []): FillFromLocale
    {
        I18nTestApp::addContentTypes($this->strapi, [self::MODEL => ['attributes' => $attributes]]);
        foreach ($components as $uid => $componentAttributes) {
            I18nTestApp::addComponents($this->strapi, [$uid => ['attributes' => $componentAttributes]]);
        }

        return new FillFromLocale($this->strapi);
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private static function transform(FillFromLocale $service, array $document): array
    {
        return $service->transformDocument($document, self::MODEL, 'fr', new Ability([]));
    }

    public function testStripsAllFieldsToRemove(): void
    {
        $doc = ['title' => 'Hello'];
        foreach (['createdAt', 'createdBy', 'updatedAt', 'updatedBy', 'id', 'documentId', 'publishedAt', 'strapi_stage', 'strapi_assignee', 'locale', 'status'] as $field) {
            $doc[$field] = 'should-be-removed';
        }

        self::assertSame(['title' => 'Hello'], self::transform($this->service(['title' => ['type' => 'string']]), $doc));
    }

    public function testStripsPasswordFields(): void
    {
        $service = $this->service(['title' => ['type' => 'string'], 'secret' => ['type' => 'password']]);
        self::assertSame(['title' => 'Hello'], self::transform($service, ['title' => 'Hello', 'secret' => 'hunter2']));
    }

    public function testPassesThroughFieldsWithNoAttributeDefinition(): void
    {
        self::assertSame(['customField' => 'value'], self::transform($this->service([]), ['customField' => 'value']));
    }

    public function testReturnsAnEmptyObjectForEmptyData(): void
    {
        self::assertSame([], self::transform($this->service([]), []));
    }

    public function testProcessesANonRepeatableComponentRecursively(): void
    {
        $service = $this->service(
            ['seo' => ['type' => 'component', 'component' => 'shared.seo', 'repeatable' => false]],
            ['shared.seo' => ['title' => ['type' => 'string'], 'secret' => ['type' => 'password']]],
        );

        self::assertSame(['seo' => ['title' => 'SEO title']], self::transform($service, ['seo' => ['title' => 'SEO title', 'secret' => 'pass', 'id' => 1]]));
        self::assertSame(['seo' => null], self::transform($service, ['seo' => null]));
    }

    public function testProcessesARepeatableComponentAndAddsTempKeys(): void
    {
        $service = $this->service(
            ['sections' => ['type' => 'component', 'component' => 'shared.section', 'repeatable' => true]],
            ['shared.section' => ['heading' => ['type' => 'string'], 'id' => ['type' => 'integer']]],
        );

        self::assertSame(['sections' => [
            ['heading' => 'First', '__temp_key__' => 'a0'],
            ['heading' => 'Second', '__temp_key__' => 'a1'],
        ]], self::transform($service, ['sections' => [['heading' => 'First', 'id' => 1], ['heading' => 'Second', 'id' => 2]]]));
    }

    public function testContinuesTempKeysAfterTheBase62Boundary(): void
    {
        $keys = FillFromLocale::generateInitialTempKeys(64);

        self::assertSame('a0', $keys[0]);
        self::assertSame('az', $keys[61]);
        self::assertSame('b00', $keys[62]);
        self::assertSame('b01', $keys[63]);
    }

    public function testProcessesDynamicZoneItemsWithTheirComponentSchema(): void
    {
        $service = $this->service(
            ['body' => ['type' => 'dynamiczone']],
            ['blocks.text' => ['content' => ['type' => 'richtext']], 'blocks.image' => ['url' => ['type' => 'string'], 'secret' => ['type' => 'password']]],
        );

        self::assertSame(['body' => [
            ['__component' => 'blocks.text', 'content' => 'Hello', '__temp_key__' => 'a0'],
            ['__component' => 'blocks.image', 'url' => 'https://example.com/img.png', '__temp_key__' => 'a1'],
        ]], self::transform($service, ['body' => [
            ['__component' => 'blocks.text', 'content' => 'Hello', 'id' => 10],
            ['__component' => 'blocks.image', 'url' => 'https://example.com/img.png', 'id' => 11, 'secret' => 'x'],
        ]]));
    }

    public function testRelationsWithoutResolvableTargetsAreEmpty(): void
    {
        $empty = ['connect' => [], 'disconnect' => []];
        $service = $this->service([
            'morph' => ['type' => 'relation', 'relation' => 'morphToMany'],
            'noTarget' => ['type' => 'relation', 'relation' => 'oneToMany'],
            'blocked' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'api::tag.tag'],
        ]);

        self::assertSame(
            ['morph' => $empty, 'noTarget' => $empty, 'blocked' => $empty],
            self::transform($service, [
                'morph' => [['documentId' => 'a', 'id' => 1]],
                'noTarget' => [['documentId' => 'a', 'id' => 1]],
                // the user cannot read api::tag.tag (empty ability)
                'blocked' => [['documentId' => 'a', 'id' => 1]],
            ]),
        );
        self::assertSame(['blocked' => $empty], self::transform($service, ['blocked' => null]));
        self::assertSame(['blocked' => $empty], self::transform($service, ['blocked' => [['id' => 1]]]));
    }
}
