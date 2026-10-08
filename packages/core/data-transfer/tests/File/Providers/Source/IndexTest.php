<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\File\Providers\Source;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\File\Providers\Source\Source;
use Strapi\DataTransfer\File\Providers\Source\Utils;
use Strapi\DataTransfer\Tests\TempDir;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Encryption\Encrypt;
use Strapi\DataTransfer\Utils\Tar\Pack;

/** Port of src/file/providers/source/__tests__/index.test.ts (backpressure has no synchronous equivalent). */
final class IndexTest extends TestCase
{
    use TempDir;

    private const array VALID_ASSET_METADATA = [
        'id' => 1,
        'name' => 'photo.jpg',
        'hash' => 'photo',
        'ext' => '.jpg',
        'mime' => 'image/jpeg',
        'size' => 1,
        'url' => '/uploads/photo.jpg',
    ];

    /** @param list<array{name: string, content: string}> $entries */
    private function createTar(array $entries): string
    {
        $tarPath = $this->tempDir() . '/archive.tar';
        $bytes = '';
        $pack = new Pack(static function (string $b) use (&$bytes): void {
            $bytes .= $b;
        });
        foreach ($entries as $entry) {
            $pack->entry(['name' => $entry['name']], $entry['content']);
        }
        $pack->finalize();
        file_put_contents($tarPath, $bytes);

        return $tarPath;
    }

    private static function provider(string $path, bool $compression = false, ?string $key = null): Source
    {
        $provider = Source::createLocalFileSourceProvider([
            'file' => ['path' => $path],
            'compression' => ['enabled' => $compression],
            'encryption' => $key === null ? ['enabled' => false] : ['enabled' => true, 'key' => $key],
        ]);
        $provider->bootstrap(Diagnostic::createDiagnosticReporter());

        return $provider;
    }

    public function testExposesCreateAssetsReadStream(): void
    {
        $provider = Source::createLocalFileSourceProvider([
            'file' => ['path' => './test-file'],
            'compression' => ['enabled' => false],
            'encryption' => ['enabled' => false],
        ]);

        self::assertTrue(method_exists($provider, 'createAssetsReadStream'));
    }

    /** @return iterable<array{string}> */
    public static function requiredFields(): iterable
    {
        foreach (['id', 'name', 'hash', 'mime', 'size', 'url'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('requiredFields')]
    public function testValidateAssetMetadataRejectsAMissingField(string $field): void
    {
        $metadata = self::VALID_ASSET_METADATA;
        unset($metadata[$field]);

        $this->expectExceptionMessage($field);
        Utils::validateAssetMetadata($metadata, 'photo.jpg');
    }

    public function testValidateAssetMetadataRejectsMetadataForADifferentUploadFilename(): void
    {
        $this->expectExceptionMessage('does not match upload filename "other.jpg"');
        Utils::validateAssetMetadata(self::VALID_ASSET_METADATA, 'other.jpg');
    }

    public function testValidateAssetMetadataAcceptsZeroByteFilesWithoutAnExtension(): void
    {
        $result = Utils::validateAssetMetadata(['ext' => null, 'size' => 0, 'url' => '/uploads/photo', 'name' => 'photo'] + self::VALID_ASSET_METADATA, 'photo');
        self::assertSame('photo', $result['hash']);
        self::assertSame(0, $result['size']);
    }

    public function testValidateAssetMetadataTreatsNullOptionalFieldsAsAbsent(): void
    {
        $result = Utils::validateAssetMetadata(['name' => 'photo', 'ext' => null, 'type' => null, 'mainHash' => null, 'url' => '/uploads/photo'] + self::VALID_ASSET_METADATA, 'photo');
        self::assertNull($result['ext']);
        self::assertNull($result['type']);
        self::assertNull($result['mainHash']);
    }

    public function testValidateAssetMetadataAcceptsLegacyFilenamesForANullExtension(): void
    {
        $result = Utils::validateAssetMetadata(['name' => 'photo', 'hash' => 'photo', 'ext' => null, 'url' => '/uploads/photo'] + self::VALID_ASSET_METADATA, 'photonull');
        self::assertSame('photo', $result['hash']);

        $metadata = ['name' => 'photo', 'hash' => 'photo', 'url' => '/uploads/photo'] + self::VALID_ASSET_METADATA;
        unset($metadata['ext']);
        self::assertSame('photo', Utils::validateAssetMetadata($metadata, 'photoundefined')['hash']);
    }

    public function testValidateAssetMetadataAcceptsResponsiveFormatMetadata(): void
    {
        $result = Utils::validateAssetMetadata(['name' => 'small_photo.jpg', 'hash' => 'small_photo', 'type' => 'small', 'mainHash' => 'photo', 'url' => '/uploads/small_photo.jpg'] + self::VALID_ASSET_METADATA, 'small_photo.jpg');
        self::assertSame('small', $result['type']);
        self::assertSame('photo', $result['mainHash']);
    }

    public function testValidateAssetMetadataRejectsInvalidOptionalFieldTypes(): void
    {
        $this->expectExceptionMessage('type');
        Utils::validateAssetMetadata(['type' => 1] + self::VALID_ASSET_METADATA, 'photo.jpg');
    }

    /** @return iterable<array{string, string}> */
    public static function unknownConversionCases(): iterable
    {
        yield ['some/path/on/posix', 'some/path/on/posix'];
        yield ['some/path/on/posix/', 'some/path/on/posix/'];
        yield ['some/path/on/posix.jpg', 'some/path/on/posix.jpg'];
        yield ['file.jpg', 'file.jpg'];
        yield ['noextension', 'noextension'];
        yield ['some\\windows\\filename.jpg', 'some/windows/filename.jpg'];
        yield ['some\\windows\\noendingslash', 'some/windows/noendingslash'];
        yield ['some\\windows\\endingslash\\', 'some/windows/endingslash/'];
        yield ['some\\windows/mixed', 'some\\windows/mixed'];
    }

    #[DataProvider('unknownConversionCases')]
    public function testUnknownPathToPosix(string $input, string $expected): void
    {
        self::assertSame($expected, Utils::unknownPathToPosix($input));
    }

    /** @return iterable<array{string, string, bool}> */
    public static function isFilePathInDirnameCases(): iterable
    {
        yield ['some/path/on/posix', 'some/path/on/posix/file.jpg', true];
        yield ['some/path/on/posix/', 'some/path/on/posix/file.jpg', true];
        yield ['./some/path/on/posix', 'some/path/on/posix/file.jpg', true];
        yield ['some/path/on/posix/', './some/path/on/posix/file.jpg', true];
        yield ['some/path/on/posix/', 'some/path/on/posix/', false];
        yield ['some/path/on/posix', 'some/path/on/posix', false];
        yield ['', './file.jpg', true];
        yield ['./', './file.jpg', true];
        yield ['noextension', './noextension/file.jpg', true];
        yield ['./noextension', './noextension/file.jpg', true];
        yield ['./noextension', 'noextension/file.jpg', true];
        yield ['noextension', 'noextension/noextension', true];
        yield ['some/path/on/win32', 'some\\path\\on\\win32\\file.jpg', true];
        yield ['some/path/on/win32/', 'some\\path\\on\\win32\\file.jpg', true];
        yield ['some/path/on/win32/', 'some\\path\\on\\win32\\', false];
        yield ['some/path/on/win32', 'some\\path\\on\\win32', false];
        yield ['', '.\\file.jpg', true];
        yield ['./', '.\\file.jpg', true];
        yield ['noextension', '.\\noextension\\file.jpg', true];
        yield ['./noextension', '.\\noextension\\file.jpg', true];
        yield ['./noextension', 'noextension\\file.jpg', true];
        yield ['noextension', 'noextension\\noextension', true];
        yield ['', 'file.jpg', true];
        yield ['noextension', 'noextension', false];
    }

    #[DataProvider('isFilePathInDirnameCases')]
    public function testIsFilePathInDirname(string $a, string $b, bool $expected): void
    {
        self::assertSame($expected, Utils::isFilePathInDirname($a, $b));
    }

    /** @return iterable<array{string, string, bool}> */
    public static function isPathEquivalentCases(): iterable
    {
        yield ['file.jpg', 'file.jpg', true];
        yield ['file.jpg', '.\\file.jpg', true];
        yield ['file.jpg', './file.jpg', true];
        yield ['./file.jpg', 'file.jpg', true];
        yield ['./file.jpg', './file.jpg', true];
        yield ['./file.jpg', '.\\file.jpg', true];
        yield ['.\\file.jpg', 'file.jpg', true];
        yield ['.\\file.jpg', './file.jpg', true];
        yield ['.\\file.jpg', '.\\file.jpg', true];
        yield ['one/two/file.jpg', 'one/two/file.jpg', true];
        yield ['one/two/file.jpg', './one/two/file.jpg', true];
        yield ['one/two/file.jpg', 'one\\two\\file.jpg', true];
        yield ['one/two/file.jpg', '.\\one\\two\\file.jpg', true];
        yield ['./one/two/file.jpg', 'one/two/file.jpg', true];
        yield ['./one/two/file.jpg', './one/two/file.jpg', true];
        yield ['./one/two/file.jpg', 'one\\two\\file.jpg', true];
        yield ['./one/two/file.jpg', '.\\one\\two\\file.jpg', true];
        yield ['one\\two\\file.jpg', 'one/two/file.jpg', true];
        yield ['one\\two\\file.jpg', './one/two/file.jpg', true];
        yield ['one\\two\\file.jpg', '.\\one\\two\\file.jpg', true];
        yield ['one\\two\\file.jpg', 'one\\two\\file.jpg', true];
        yield ['.\\one\\two\\file.jpg', 'one/two/file.jpg', true];
        yield ['.\\one\\two\\file.jpg', './one/two/file.jpg', true];
        yield ['.\\one\\two\\file.jpg', '.\\one\\two\\file.jpg', true];
        yield ['.\\one\\two\\file.jpg', 'one\\two\\file.jpg', true];
        yield [".\\one\\two\\fi ' ^&*() le.jpg", "one/two/fi ' ^&*() le.jpg", true];
        yield ['test/backslash\\file.jpg', 'test/backslash\\file.jpg', true];
        yield ['file.jpg', 'one/file.jpg', false];
        yield ['file.jpg', 'one\\file.jpg', false];
        yield ['file.jpg', '/file.jpg', false];
        yield ['file.jpg', '\\file.jpg', false];
        yield ['one/file.jpg', '\\one\\file.jpg', false];
        yield ['one/file.jpg', '/one/file.jpg', false];
        yield ['one/file.jpg', 'file.jpg', false];
        yield ['test/mixedslash\\file.jpg', 'test/mixedslash/file.jpg', false];
    }

    #[DataProvider('isPathEquivalentCases')]
    public function testIsPathEquivalent(string $a, string $b, bool $expected): void
    {
        self::assertSame($expected, Utils::isPathEquivalent($a, $b));
    }

    /** @return list<array{name: string, content: string}> */
    private static function archiveEntries(?string $sidecar = null, bool $withSidecar = true): array
    {
        $entries = [
            ['name' => 'metadata.json', 'content' => (string) json_encode(['createdAt' => gmdate('c'), 'strapi' => ['version' => '1.0.0']])],
            ['name' => 'assets/uploads/photo.jpg', 'content' => 'jpeg-bytes'],
        ];
        if ($withSidecar) {
            $entries[] = ['name' => 'assets/metadata/photo.jpg.json', 'content' => $sidecar ?? (string) json_encode(self::VALID_ASSET_METADATA)];
        }

        return $entries;
    }

    public function testAcceptsAnArchiveWhenEveryUploadHasValidSidecarMetadata(): void
    {
        $provider = self::provider($this->createTar(self::archiveEntries()));
        $provider->validateStage('assets');

        $assets = [];
        foreach ($provider->createAssetsReadStream() as $asset) {
            $assets[] = $asset;
            self::assertSame('jpeg-bytes', implode('', iterator_to_array($asset['stream'], false)));
        }
        self::assertCount(1, $assets);
        self::assertSame(1, $assets[0]['metadata']['id']);
        self::assertSame('photo', $assets[0]['metadata']['hash']);
    }

    public function testValidatesAndRestoresACompressedEncryptedArchive(): void
    {
        $tarPath = $this->createTar(self::archiveEntries());
        $key = 'preflight-test-key';
        $cipher = Encrypt::createEncryptionCipher($key);
        file_put_contents($tarPath, $cipher->update((string) gzencode((string) file_get_contents($tarPath))) . $cipher->final());

        $provider = self::provider($tarPath, true, $key);
        $provider->validateStage('assets');

        $assets = iterator_to_array($provider->createAssetsReadStream(), false);
        self::assertCount(1, $assets);
        self::assertSame('photo', $assets[0]['metadata']['hash']);
    }

    public function testRejectsAnArchiveWithAMissingAssetSidecar(): void
    {
        $provider = self::provider($this->createTar(self::archiveEntries(withSidecar: false)));

        $this->expectExceptionMessage('Asset metadata preflight failed: missing sidecar metadata for "photo.jpg"');
        $provider->validateStage('assets');
    }

    public function testRejectsAnArchiveWithIncompleteObjectAssetMetadata(): void
    {
        $provider = self::provider($this->createTar(self::archiveEntries('{}')));

        $this->expectExceptionMessage('Asset sidecar metadata has invalid required fields');
        $provider->validateStage('assets');
    }

    public function testRejectsAnArchiveWithNonObjectAssetSidecarJson(): void
    {
        $provider = self::provider($this->createTar(self::archiveEntries('null')));

        $this->expectExceptionMessage('Asset sidecar metadata must be a JSON object');
        $provider->validateStage('assets');
    }

    public function testRejectsAnArchiveWithMalformedAssetSidecarJson(): void
    {
        $provider = self::provider($this->createTar(self::archiveEntries('{not valid json')));

        $this->expectExceptionMessage('Asset metadata preflight failed for "photo.jpg.json"');
        $provider->validateStage('assets');
    }

    /**
     * fixtures/node-export.tar.gz.enc was written by upstream's own
     * `file.providers.createLocalFileDestinationProvider` (@strapi/data-transfer 5.56, Node), with
     * `{ encryption: { enabled: true, key: 'fixture-key' }, compression: { enabled: true } }`: one
     * schema, two entities, a morph link, a core-store row and a 3000-byte asset whose name needs a
     * PAX header. The PHP source reads every stage of it.
     */
    public function testReadsAnArchiveWrittenByUpstream(): void
    {
        $provider = self::provider(dirname(__DIR__, 3) . '/fixtures/node-export.tar.gz.enc', true, 'fixture-key');

        self::assertSame(['createdAt' => '2026-01-01T00:00:00.000Z', 'strapi' => ['version' => '5.56.0']], $provider->getMetadata());
        self::assertSame(['api::article.article'], array_column(iterator_to_array($provider->createSchemasReadStream(), false), 'uid'));

        $entities = iterator_to_array($provider->createEntitiesReadStream(), false);
        self::assertCount(2, $entities);
        self::assertSame(['documentId' => 'abc', 'title' => 'Héllo 🌍', 'publishedAt' => null, 'size' => 1.5, 'count' => 2], $entities[0]['data']);

        $links = iterator_to_array($provider->createLinksReadStream(), false);
        self::assertSame('relation.morph', $links[0]['kind']);

        $configuration = iterator_to_array($provider->createConfigurationReadStream(), false);
        self::assertSame('core-store', $configuration[0]['type']);

        $provider->validateStage('assets');
        $assets = [];
        foreach ($provider->createAssetsReadStream() as $asset) {
            $assets[$asset['filename']] = implode('', iterator_to_array($asset['stream'], false));
        }
        $name = 'a-very-long-file-name-that-needs-a-pax-header-because-it-exceeds-one-hundred-characters-of-ustar_abc.bin';
        self::assertSame([$name], array_keys($assets));
        self::assertSame(implode('', array_map(static fn (int $i): string => chr($i % 256), range(0, 2999))), $assets[$name]);
    }
}
