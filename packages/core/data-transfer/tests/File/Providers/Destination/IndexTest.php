<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\File\Providers\Destination;

use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\File\Providers\Destination\Destination;
use Strapi\DataTransfer\File\Providers\Source\Source;
use Strapi\DataTransfer\Tests\TempDir;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Stream\Writable;
use Strapi\DataTransfer\Utils\Tar\Parser;

/**
 * Port of src/file/providers/destination/__tests__/index.test.ts. Upstream mocks `fs`, the cipher
 * and the tar utils and checks the stream chain; here the archive is really written (to a temp
 * dir) and read back, which covers the same steps. Backpressure has no synchronous equivalent.
 */
final class IndexTest extends TestCase
{
    use TempDir;

    /**
     * @param array{enabled: bool, key?: string} $encryption
     * @return array{encryption: array{enabled: bool, key?: string}, compression: array{enabled: bool}, file: array{path: string}}
     */
    private function options(array $encryption = ['enabled' => false], bool $compression = false): array
    {
        return [
            'encryption' => $encryption,
            'compression' => ['enabled' => $compression],
            'file' => ['path' => $this->tempDir() . '/test-file'],
        ];
    }

    public function testThrowsAnErrorIfEncryptionIsEnabledAndTheKeyIsNotProvided(): void
    {
        $provider = Destination::createLocalFileDestinationProvider($this->options(['enabled' => true]));

        $this->expectExceptionMessage("Can't encrypt without a key");
        $provider->bootstrap();
    }

    public function testAddsGzExtensionWhenCompressionIsEnabled(): void
    {
        $options = $this->options(compression: true);
        $provider = Destination::createLocalFileDestinationProvider($options);
        $provider->bootstrap();

        self::assertSame("{$options['file']['path']}.tar.gz", $provider->results['file']['path'] ?? null);
        $provider->close();
    }

    public function testAddsEncExtensionWhenEncryptionIsEnabled(): void
    {
        $options = $this->options(['enabled' => true, 'key' => 'key']);
        $provider = Destination::createLocalFileDestinationProvider($options);
        $provider->bootstrap();

        self::assertSame("{$options['file']['path']}.tar.enc", $provider->results['file']['path'] ?? null);
        $provider->close();
    }

    public function testAddsGzEncExtensionWhenEncryptionAndCompressionAreEnabled(): void
    {
        $options = $this->options(['enabled' => true, 'key' => 'key'], true);
        $provider = Destination::createLocalFileDestinationProvider($options);
        $provider->bootstrap();

        self::assertSame("{$options['file']['path']}.tar.gz.enc", $provider->results['file']['path'] ?? null);
        $provider->close();
    }

    public function testStageWriteStreamsCreateJsonlTarEntries(): void
    {
        $options = $this->options();
        $provider = Destination::createLocalFileDestinationProvider($options);
        $provider->setMetadata('source', ['createdAt' => '2026-01-01T00:00:00.000Z', 'strapi' => ['version' => '5.56.0']]);
        $provider->bootstrap(Diagnostic::createDiagnosticReporter());

        foreach (['schemas' => $provider->createSchemasWriteStream(), 'entities' => $provider->createEntitiesWriteStream(), 'links' => $provider->createLinksWriteStream(), 'configuration' => $provider->createConfigurationWriteStream()] as $stage => $stream) {
            self::assertInstanceOf(Writable::class, $stream);
            $stream->write(['stage' => $stage]);
            $stream->end();
        }
        $provider->close();

        $names = [];
        $contents = [];
        $archive = (string) file_get_contents("{$options['file']['path']}.tar");
        foreach (Parser::entries((static fn () => yield $archive)()) as $entry) {
            $names[] = $entry->path;
            $contents[$entry->path] = implode('', iterator_to_array($entry->chunks(), false));
        }

        self::assertSame([
            'schemas/schemas_00001.jsonl',
            'entities/entities_00001.jsonl',
            'links/links_00001.jsonl',
            'configuration/configuration_00001.jsonl',
            'metadata.json',
        ], $names);
        self::assertSame("{\"stage\":\"entities\"}\n", $contents['entities/entities_00001.jsonl']);
        // JSON.stringify(metadata, null, 2)
        self::assertSame("{\n  \"createdAt\": \"2026-01-01T00:00:00.000Z\",\n  \"strapi\": {\n    \"version\": \"5.56.0\"\n  }\n}", $contents['metadata.json']);
    }

    public function testWrittenArchiveReadsBackThroughTheFileSource(): void
    {
        $options = $this->options(['enabled' => true, 'key' => 'round-trip'], true);
        $destination = Destination::createLocalFileDestinationProvider($options);
        $destination->setMetadata('source', ['createdAt' => '2026-01-01T00:00:00.000Z', 'strapi' => ['version' => '5.56.0']]);
        $destination->bootstrap(Diagnostic::createDiagnosticReporter());

        $entities = $destination->createEntitiesWriteStream();
        $entities->write(['type' => 'api::article.article', 'id' => 1, 'data' => ['title' => 'Héllo 🌍']]);
        $entities->end();

        $bytes = random_bytes(70000);
        $assets = $destination->createAssetsWriteStream();
        $assets->write([
            'filename' => 'photo.jpg',
            'filepath' => '/tmp/photo.jpg',
            'stats' => ['size' => strlen($bytes)],
            'stream' => [substr($bytes, 0, 1000), substr($bytes, 1000)],
            'metadata' => ['id' => 1, 'name' => 'photo.jpg', 'hash' => 'photo', 'ext' => '.jpg', 'mime' => 'image/jpeg', 'size' => 68.36, 'url' => '/uploads/photo.jpg'],
        ]);
        $assets->end();
        $destination->close();

        $source = Source::createLocalFileSourceProvider([
            'file' => ['path' => (string) ($destination->results['file']['path'] ?? '')],
            'encryption' => ['enabled' => true, 'key' => 'round-trip'],
            'compression' => ['enabled' => true],
        ]);
        $source->bootstrap(Diagnostic::createDiagnosticReporter());

        self::assertSame(['createdAt' => '2026-01-01T00:00:00.000Z', 'strapi' => ['version' => '5.56.0']], $source->getMetadata());
        self::assertSame([['type' => 'api::article.article', 'id' => 1, 'data' => ['title' => 'Héllo 🌍']]], iterator_to_array($source->createEntitiesReadStream(), false));

        $source->validateStage('assets');
        $read = [];
        foreach ($source->createAssetsReadStream() as $asset) {
            $read[$asset['filename']] = implode('', iterator_to_array($asset['stream'], false));
        }
        self::assertSame(['photo.jpg' => $bytes], $read);
    }
}
