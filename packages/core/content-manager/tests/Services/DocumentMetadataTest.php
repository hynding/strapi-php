<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\DocumentMetadata;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/__tests__/document-metadata.test.ts.
 *
 * The i18n plugin is not ported: the default locale comes from core's localization service,
 * whose provider stands in for upstream's mocked `strapi.plugin('i18n')`. `getManyAvailableStatus`
 * runs against a booted app (upstream mocks `strapi.query().findMany`).
 */
final class DocumentMetadataTest extends TestCase
{
    private static function provider(\Closure $getDefaultLocale): object
    {
        return new class ($getDefaultLocale) {
            public function __construct(private readonly \Closure $getDefaultLocale)
            {
            }

            public function isLocalizedContentType(mixed $model): bool
            {
                return true;
            }

            public function getDefaultLocale(): mixed
            {
                return ($this->getDefaultLocale)();
            }

            /** @return list<mixed> */
            public function getLocales(): array
            {
                return [];
            }

            /** @return list<string> */
            public function getNestedPopulateOfNonLocalizedAttributes(string $uid): array
            {
                return [];
            }

            /** @return list<string> */
            public function getNonLocalizedAttributes(mixed $model): array
            {
                return [];
            }

            /** @param array<string, mixed> $entry */
            public function fillNonLocalizedAttributes(array &$entry, mixed $related, mixed $options): void
            {
            }
        };
    }

    private static function createService(?\Closure $getDefaultLocale = null): DocumentMetadata
    {
        $strapi = StubStrapi::create();
        StubStrapi::addContentTypes($strapi, [
            'api::article.article' => ['pluginOptions' => ['i18n' => ['localized' => true]]],
        ]);
        $strapi->localization()->register(self::provider($getDefaultLocale ?? static fn (): string => 'en'));

        return new DocumentMetadata($strapi);
    }

    /**
     * @param list<array<string, mixed>> $result
     * @return list<mixed>
     */
    private static function locales(array $result): array
    {
        return array_map(static fn (array $entry): mixed => $entry['locale'] ?? null, $result);
    }

    public function testPlacesTheDefaultLocaleFirstInTheResult(): void
    {
        $result = self::createService()->getAvailableLocales(
            'api::article.article',
            // current locale (excluded from the result)
            ['id' => 1, 'documentId' => 'doc-1', 'locale' => 'nl'],
            [
                ['id' => 2, 'documentId' => 'doc-1', 'locale' => 'fr'],
                ['id' => 3, 'documentId' => 'doc-1', 'locale' => 'en'],
                ['id' => 4, 'documentId' => 'doc-1', 'locale' => 'de'],
            ],
        );

        self::assertSame(['en', 'fr', 'de'], self::locales($result));
    }

    public function testPreservesTheOriginalOrderOfNonDefaultLocales(): void
    {
        $result = self::createService()->getAvailableLocales(
            'api::article.article',
            ['id' => 1, 'documentId' => 'doc-1', 'locale' => 'nl'],
            [
                ['id' => 2, 'documentId' => 'doc-1', 'locale' => 'de'],
                ['id' => 3, 'documentId' => 'doc-1', 'locale' => 'fr'],
                ['id' => 4, 'documentId' => 'doc-1', 'locale' => 'en'],
                ['id' => 5, 'documentId' => 'doc-1', 'locale' => 'es'],
            ],
        );

        self::assertSame(['en', 'de', 'fr', 'es'], self::locales($result));
    }

    public function testReturnsTheResultUntouchedIfTheDefaultLocaleIsNotInTheAvailableLocales(): void
    {
        $result = self::createService()->getAvailableLocales(
            'api::article.article',
            ['id' => 1, 'documentId' => 'doc-1', 'locale' => 'nl'],
            [
                ['id' => 2, 'documentId' => 'doc-1', 'locale' => 'fr'],
                ['id' => 3, 'documentId' => 'doc-1', 'locale' => 'de'],
            ],
        );

        self::assertSame(['fr', 'de'], self::locales($result));
    }

    public function testNoOpsWhenTheDefaultLocaleIsUnavailable(): void
    {
        $result = self::createService(static fn (): mixed => null)->getAvailableLocales(
            'api::article.article',
            ['id' => 1, 'documentId' => 'doc-1', 'locale' => 'nl'],
            [
                ['id' => 2, 'documentId' => 'doc-1', 'locale' => 'fr'],
                ['id' => 3, 'documentId' => 'doc-1', 'locale' => 'en'],
            ],
        );

        self::assertSame(['fr', 'en'], self::locales($result));
    }

    public function testNoOpsWhenGetDefaultLocaleThrows(): void
    {
        $result = self::createService(static fn (): never => throw new \RuntimeException('boom'))->getAvailableLocales(
            'api::article.article',
            ['id' => 1, 'documentId' => 'doc-1', 'locale' => 'nl'],
            [
                ['id' => 2, 'documentId' => 'doc-1', 'locale' => 'fr'],
                ['id' => 3, 'documentId' => 'doc-1', 'locale' => 'en'],
            ],
        );

        self::assertSame(['fr', 'en'], self::locales($result));
    }

    public function testExcludesTheCurrentLocaleFromTheResult(): void
    {
        $result = self::createService()->getAvailableLocales(
            'api::article.article',
            ['id' => 1, 'documentId' => 'doc-1', 'locale' => 'en'],
            [
                ['id' => 1, 'documentId' => 'doc-1', 'locale' => 'en'],
                ['id' => 2, 'documentId' => 'doc-1', 'locale' => 'fr'],
            ],
        );

        self::assertSame(['fr'], self::locales($result));
    }

    public function testGetStatus(): void
    {
        $service = self::createService();

        self::assertSame('draft', $service->getStatus(['publishedAt' => null]));
        self::assertSame('published', $service->getStatus(['publishedAt' => '2024-01-01T00:00:00.000Z']));
        self::assertSame('modified', $service->getStatus(
            ['publishedAt' => null, 'updatedAt' => '2024-01-02T00:00:00.000Z'],
            [['publishedAt' => '2024-01-01T00:00:00.000Z', 'updatedAt' => '2024-01-01T00:00:00.000Z']],
        ));
        self::assertSame('published', $service->getStatus(
            ['publishedAt' => null, 'updatedAt' => '2024-01-01T00:00:00.000Z'],
            [['publishedAt' => '2024-01-01T00:00:00.000Z', 'updatedAt' => '2024-01-01T00:00:00.000Z']],
        ));
    }

    private static Strapi $booted;

    public static function setUpBeforeClass(): void
    {
        self::$booted = StubStrapi::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$booted->destroy();
    }

    /**
     * getManyAvailableStatus: the batch mixes localized and non-localized versions. The counterparts
     * of both are found (upstream asserts the `$or` where clause on a mocked query).
     */
    public function testGetManyAvailableStatusAlsoMatchesNonLocalizedVersionsWhenTheBatchMixesBoth(): void
    {
        $strapi = self::$booted;
        $uid = 'api::tag.tag';
        $a = $strapi->documents($uid)->create(['data' => ['name' => 'a']]);
        $b = $strapi->documents($uid)->create(['data' => ['name' => 'b']]);
        $strapi->documents($uid)->publish(['documentId' => $a['documentId']]);
        $strapi->documents($uid)->publish(['documentId' => $b['documentId']]);
        // a draft row carrying a locale next to one without
        $strapi->db()->query($uid)->update(['where' => ['id' => $a['id']], 'data' => ['locale' => 'fr']]);
        $strapi->db()->query($uid)->updateMany(['where' => ['documentId' => $a['documentId'], 'publishedAt' => ['$notNull' => true]], 'data' => ['locale' => 'fr']]);

        $service = new DocumentMetadata($strapi);
        $result = $service->getManyAvailableStatus($uid, [
            ['id' => $a['id'], 'documentId' => $a['documentId'], 'locale' => 'fr', 'publishedAt' => null],
            ['id' => $b['id'], 'documentId' => $b['documentId'], 'publishedAt' => null],
        ]);

        $documentIds = array_map(static fn (array $row): mixed => $row['documentId'], $result);
        sort($documentIds);
        $expected = [$a['documentId'], $b['documentId']];
        sort($expected);
        self::assertSame($expected, $documentIds);
    }
}
