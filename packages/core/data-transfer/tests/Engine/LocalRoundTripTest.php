<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Engine;

use Strapi\DataTransfer\Engine\Engine;
use Strapi\DataTransfer\File\Providers\Destination\Destination;
use Strapi\DataTransfer\File\Providers\Source\Source;
use Strapi\DataTransfer\Strapi\Providers\LocalDestination\LocalDestination;
use Strapi\DataTransfer\Strapi\Providers\LocalSource\LocalSource;
use Strapi\DataTransfer\Tests\BootedAppTestCase;

/**
 * Not an upstream test file: what `strapi export` then `strapi import` do, end to end on the fixture
 * app. The local Strapi source writes an encrypted, compressed archive through the engine; the
 * archive is then restored over the same instance (every entity deleted and re-created, so ids change) and the
 * content, relations, components, dynamic zone, media link, asset bytes and core-store
 * configuration must come back identical.
 */
final class LocalRoundTripTest extends BootedAppTestCase
{
    private const string KEY = 'round-trip-key';

    public function testExportThenImportRestoresTheSameContent(): void
    {
        $strapi = self::strapi();
        $assetBytes = random_bytes(20000);
        file_put_contents(self::$appDir . '/public/uploads/cover_abc.png', $assetBytes);

        $file = $strapi->db()->query('plugin::upload.file')->create(['data' => [
            'name' => 'cover.png', 'hash' => 'cover_abc', 'ext' => '.png', 'mime' => 'image/png', 'size' => 19.53,
            'url' => '/uploads/cover_abc.png', 'provider' => 'local', 'folderPath' => '/',
        ]]);
        $category = $strapi->documents('api::category.category')->create(['data' => ['name' => 'News']]);
        $strapi->documents('api::article.article')->create(['data' => [
            'title' => 'Héllo 🌍',
            'category' => $category['documentId'],
            'cover' => $file['id'],
            'block' => ['text' => 'single block'],
            'blocks' => [['__component' => 'shared.block', 'text' => 'dz block']],
        ]]);
        $strapi->store()->set(['type' => 'plugin', 'name' => 'data-transfer-test', 'key' => 'settings', 'value' => ['answer' => 42]]);

        $before = $this->snapshot();
        self::assertSame('Héllo 🌍', $before['articles'][0]['title']);

        $archive = sys_get_temp_dir() . '/strapi-dts-roundtrip-' . bin2hex(random_bytes(4));
        $export = Engine::createTransferEngine(
            LocalSource::createLocalStrapiSourceProvider(['getStrapi' => static fn () => $strapi, 'autoDestroy' => false]),
            Destination::createLocalFileDestinationProvider([
                'file' => ['path' => $archive],
                'encryption' => ['enabled' => true, 'key' => self::KEY],
                'compression' => ['enabled' => true],
            ]),
            ['versionStrategy' => 'ignore', 'schemaStrategy' => 'ignore'],
        );
        $exported = $export->transfer();
        self::assertGreaterThan(0, $exported['engine']['entities']['count'] ?? 0);
        self::assertSame(1, $exported['engine']['assets']['count'] ?? null);
        self::assertFileExists("{$archive}.tar.gz.enc");

        $import = Engine::createTransferEngine(
            Source::createLocalFileSourceProvider([
                'file' => ['path' => "{$archive}.tar.gz.enc"],
                'encryption' => ['enabled' => true, 'key' => self::KEY],
                'compression' => ['enabled' => true],
            ]),
            LocalDestination::createLocalStrapiDestinationProvider([
                'getStrapi' => static fn () => $strapi,
                'autoDestroy' => false,
                'strategy' => 'restore',
                'restore' => ['assets' => true, 'entities' => ['exclude' => []], 'configuration' => ['coreStore' => true, 'webhook' => true]],
            ]),
            ['versionStrategy' => 'ignore', 'schemaStrategy' => 'strict'],
        );
        $import->transfer();
        @unlink("{$archive}.tar.gz.enc");

        self::assertEquals($before, $this->snapshot());
        self::assertSame($assetBytes, file_get_contents(self::$appDir . '/public/uploads/cover_abc.png'));
        self::assertSame(['answer' => 42], $strapi->store()->get(['type' => 'plugin', 'name' => 'data-transfer-test', 'key' => 'settings']));
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $strapi = self::strapi();
        $articles = $strapi->db()->query('api::article.article')->findMany([
            'populate' => ['category' => true, 'cover' => true, 'block' => true, 'blocks' => true],
            'orderBy' => ['id' => 'asc'],
        ]);

        return [
            'articles' => array_map(static fn (array $a): array => [
                'documentId' => $a['documentId'],
                'title' => $a['title'],
                'category' => $a['category']['name'] ?? null,
                'cover' => $a['cover']['hash'] ?? null,
                'block' => $a['block']['text'] ?? null,
                'blocks' => array_map(static fn (array $b): ?string => $b['text'] ?? null, $a['blocks'] ?? []),
            ], $articles),
            'categories' => $strapi->db()->query('api::category.category')->count([]),
            'files' => $strapi->db()->query('plugin::upload.file')->count([]),
        ];
    }
}
