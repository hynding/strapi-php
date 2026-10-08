<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Directory\Providers\Source;

use Strapi\DataTransfer\Directory\Providers\Destination\Destination as DirectoryDestination;
use Strapi\DataTransfer\Errors\Providers\ProviderInitializationError;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Errors\Providers\ProviderValidationError;
use Strapi\DataTransfer\File\Providers\Source\Utils as FileSourceUtils;
use Strapi\DataTransfer\Types\Providers\ISourceProvider;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Schema;
use Strapi\DataTransfer\Utils\Stream as StreamUtils;
use Strapi\DataTransfer\Utils\Stream\Bytes;
use Strapi\DataTransfer\Utils\Stream\Jsonl;

/**
 * Port of src/directory/providers/source/index.ts: `createLocalDirectorySourceProvider()`, the
 * source that reads an unpacked Strapi export directory.
 *
 * @phpstan-type DirectorySourceOptions array{directory: array{path: string}}
 */
final class Source implements ISourceProvider
{
    public const string METADATA_FILE_PATH = 'metadata.json';

    public string $type = 'source';

    public string $name = 'source::local-directory';

    /** @var DirectorySourceOptions */
    public array $options;

    /** @var array<string, mixed>|null */
    public ?array $results = null;

    private string $rootResolved;

    /** @var array<string, mixed>|null */
    private ?array $metadata = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $assetMetadata = null;

    private ?Diagnostic $diagnostics = null;

    /** @param DirectorySourceOptions $options */
    public function __construct(array $options)
    {
        $this->options = $options;
        $this->rootResolved = DirectoryDestination::resolve($options['directory']['path']);
    }

    /** @param DirectorySourceOptions $options */
    public static function createLocalDirectorySourceProvider(array $options): self
    {
        return new self($options);
    }

    private function reportInfo(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'directory-source-provider',
            ],
            'kind' => 'info',
        ]);
    }

    private static function isPathInsideRoot(string $root, string $candidate): bool
    {
        return $candidate === $root || str_starts_with($candidate, rtrim($root, '/') . '/');
    }

    /** Resolve a posix-style relative path under the export root; rejects escapes. */
    private function safePath(string ...$posixSegments): string
    {
        $joined = FileSourceUtils::posixNormalize(implode('/', $posixSegments));
        $segments = array_values(array_filter(explode('/', $joined), static fn (string $s): bool => $s !== ''));
        $resolved = DirectoryDestination::resolve($this->rootResolved . '/' . implode('/', $segments));
        if (!self::isPathInsideRoot($this->rootResolved, $resolved)) {
            throw new ProviderInitializationError("Invalid path \"{$joined}\" — escapes backup directory");
        }

        return $resolved;
    }

    public function bootstrap(?Diagnostic $diagnostics = null): void
    {
        $this->diagnostics = $diagnostics;
        $root = $this->rootResolved;

        try {
            if (!file_exists($root)) {
                throw new \RuntimeException("ENOENT: no such file or directory, stat '{$root}'");
            }
            if (!is_dir($root)) {
                throw new ProviderInitializationError("Path '{$root}' is not a directory.");
            }
            $this->loadMetadata();
        } catch (ProviderInitializationError $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ProviderInitializationError("Directory '{$root}' is not a valid Strapi data export.");
        }

        if ($this->metadata === null) {
            throw new ProviderInitializationError('Could not load metadata from Strapi data export.');
        }
    }

    private function loadMetadata(): void
    {
        $metadataPath = $this->safePath(self::METADATA_FILE_PATH);
        if (!file_exists($metadataPath)) {
            throw new ProviderInitializationError('Missing ' . self::METADATA_FILE_PATH . " in export directory '{$this->rootResolved}'.");
        }
        $metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        $this->metadata = is_array($metadata) ? $metadata : null;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        $this->reportInfo('getting metadata');
        if ($this->metadata === null) {
            $this->loadMetadata();
        }

        return $this->metadata;
    }

    /** @return array<string, array<string, mixed>> */
    public function getSchemas(): array
    {
        $this->reportInfo('getting schemas');
        $schemaCollection = StreamUtils::collect($this->createSchemasReadStream());

        if ($schemaCollection === []) {
            throw new ProviderInitializationError('Could not load schemas from Strapi data export.');
        }

        $schemas = [];
        foreach ($schemaCollection as $schema) {
            if (is_array($schema)) {
                $schemas[(string) ($schema['uid'] ?? 'undefined')] = $schema;
            }
        }

        return Schema::schemasToValidJSON($schemas);
    }

    public function validateStage(string $stage): void
    {
        if ($stage !== 'assets') {
            return;
        }

        $uploadsDir = $this->safePath('assets', 'uploads');
        if (!file_exists($uploadsDir)) {
            $this->assetMetadata = [];

            return;
        }

        $metadata = [];
        $names = self::readdir($uploadsDir);
        foreach ($names as $name) {
            if (!is_file("{$uploadsDir}/{$name}")) {
                continue;
            }

            try {
                $metadata[$name] = $this->readAssetMetadata($name);
            } catch (\Throwable $error) {
                $reason = $error instanceof \JsonException ? "Unexpected token in JSON: {$error->getMessage()}" : $error->getMessage();
                throw new ProviderValidationError("Asset metadata preflight failed for \"{$name}\": {$reason}. The destination was not modified; re-export and try again.", ['error' => $error]);
            }
        }

        $this->assetMetadata = $metadata;
    }

    /** @return list<string> sorted entry names */
    private static function readdir(string $dir): array
    {
        $names = array_values(array_filter(scandir($dir) ?: [], static fn (string $n): bool => $n !== '.' && $n !== '..'));
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return \Generator<int, mixed> */
    public function createEntitiesReadStream(): \Generator
    {
        $this->reportInfo('creating entities read stream');

        return $this->streamJsonlDirectory('entities');
    }

    /** @return \Generator<int, mixed> */
    public function createSchemasReadStream(): \Generator
    {
        $this->reportInfo('creating schemas read stream');

        return $this->streamJsonlDirectory('schemas');
    }

    /** @return \Generator<int, mixed> */
    public function createLinksReadStream(): \Generator
    {
        $this->reportInfo('creating links read stream');

        return $this->streamJsonlDirectory('links');
    }

    /** @return \Generator<int, mixed> */
    public function createConfigurationReadStream(): \Generator
    {
        $this->reportInfo('creating configuration read stream');

        return $this->streamJsonlDirectory('configuration');
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function createAssetsReadStream(): \Generator
    {
        $uploadsDir = $this->safePath('assets', 'uploads');
        $this->reportInfo('creating assets read stream');

        return $this->pipeAssetsToStream($uploadsDir);
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function pipeAssetsToStream(string $uploadsDir): \Generator
    {
        if (!file_exists($uploadsDir)) {
            return;
        }

        foreach (self::readdir($uploadsDir) as $name) {
            $absUpload = "{$uploadsDir}/{$name}";
            if (!is_file($absUpload)) {
                continue;
            }

            $metadata = $this->assetMetadata[$name] ?? null;
            if ($metadata === null) {
                try {
                    $metadata = $this->readAssetMetadata($name);
                } catch (\Throwable $error) {
                    throw new ProviderTransferError("Failed to read metadata for {$name}", [
                        'details' => ['error' => $error],
                    ]);
                }
            }

            yield [
                'metadata' => $metadata,
                'filename' => $name,
                'filepath' => FileSourceUtils::unknownPathToPosix("assets/uploads/{$name}"),
                'stats' => ['size' => (int) filesize($absUpload)],
                'stream' => Bytes::readFile($absUpload),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function readAssetMetadata(string $filename): array
    {
        $metadataPath = $this->safePath('assets', 'metadata', "{$filename}.json");
        if (!is_file($metadataPath)) {
            throw new \RuntimeException("ENOENT: no such file or directory, open '{$metadataPath}'");
        }

        return FileSourceUtils::validateAssetMetadata(json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR), $filename);
    }

    /** @return list<string> */
    private function listJsonlFiles(string $posixSubdir): array
    {
        $dirAbs = $this->safePath(...array_values(array_filter(explode('/', $posixSubdir), static fn (string $s): bool => $s !== '')));
        if (!is_dir($dirAbs)) {
            return [];
        }

        return array_map(
            static fn (string $n): string => "{$dirAbs}/{$n}",
            array_values(array_filter(self::readdir($dirAbs), static fn (string $n): bool => str_ends_with($n, '.jsonl')))
        );
    }

    /** @return \Generator<int, mixed> */
    private function streamJsonlDirectory(string $posixSubdir): \Generator
    {
        $this->reportInfo("streaming jsonl from {$posixSubdir}");

        return $this->pipeJsonlDirectoryToStream($posixSubdir);
    }

    /** @return \Generator<int, mixed> */
    private function pipeJsonlDirectoryToStream(string $posixSubdir): \Generator
    {
        foreach ($this->listJsonlFiles($posixSubdir) as $absPath) {
            try {
                foreach (Jsonl::parse(Bytes::readFile($absPath)) as $chunk) {
                    yield $chunk;
                }
            } catch (\Throwable $e) {
                throw new ProviderTransferError("Error parsing JSONL in {$absPath}: {$e->getMessage()}", [
                    'details' => [
                        'error' => $e,
                    ],
                ]);
            }
        }
    }
}
