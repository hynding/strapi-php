<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\File\Providers\Source;

use Strapi\DataTransfer\Errors\Providers\ProviderInitializationError;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Errors\Providers\ProviderValidationError;
use Strapi\DataTransfer\Types\Providers\ISourceProvider;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Encryption\Decrypt;
use Strapi\DataTransfer\Utils\Schema;
use Strapi\DataTransfer\Utils\Stream as StreamUtils;
use Strapi\DataTransfer\Utils\Stream\Bytes;
use Strapi\DataTransfer\Utils\Stream\Jsonl;
use Strapi\DataTransfer\Utils\Tar\Parser;

/**
 * Port of src/file/providers/source/index.ts: `createLocalFileSourceProvider()`, the source that
 * reads a Strapi export archive (`.tar`, `.tar.gz`, `.tar.gz.enc`).
 *
 * The archive is read sequentially, as upstream's tar `Parser` does: every stage re-opens the
 * file and streams it through (decryption →) (gunzip →) the tar reader.
 *
 * Options: `file.path`, `encryption.enabled` / `encryption.key`, `compression.enabled`.
 *
 * @phpstan-type FileSourceOptions array{file: array{path: string}, encryption: array{enabled: bool, key?: string|null}, compression: array{enabled: bool}}
 */
final class Source implements ISourceProvider
{
    /**
     * Constant for the metadata file path
     */
    public const string METADATA_FILE_PATH = 'metadata.json';

    public string $type = 'source';

    public string $name = 'source::local-file';

    /** @var FileSourceOptions */
    public array $options;

    /** @var array<string, mixed>|null */
    public ?array $results = null;

    /** @var array<string, mixed>|null */
    private ?array $metadata = null;

    /** @var array<string, array<string, mixed>>|null filename → validated sidecar metadata */
    private ?array $assetMetadata = null;

    private ?Diagnostic $diagnostics = null;

    /** @param FileSourceOptions $options */
    public function __construct(array $options)
    {
        $this->options = $options;

        $encryption = $this->options['encryption'];

        if (($encryption['enabled'] ?? false) && !array_key_exists('key', $encryption)) {
            throw new \RuntimeException('Missing encryption key');
        }
    }

    /** @param FileSourceOptions $options */
    public static function createLocalFileSourceProvider(array $options): self
    {
        return new self($options);
    }

    private function reportInfo(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'file-source-provider',
            ],
            'kind' => 'info',
        ]);
    }

    /**
     * Pre flight checks regarding the provided options, making sure that the file can be opened (decrypted, decompressed), etc.
     */
    public function bootstrap(?Diagnostic $diagnostics = null): void
    {
        $this->diagnostics = $diagnostics;
        $filePath = $this->options['file']['path'];

        try {
            // Read the metadata to ensure the file can be parsed
            $this->loadMetadata();
            // TODO: we might also need to read the schema.jsonl files & implements a custom stream-check
        } catch (\Throwable) {
            if ($this->options['encryption']['enabled'] ?? false) {
                throw new ProviderInitializationError("Key is incorrect or the file '{$filePath}' is not a valid Strapi data file.");
            }
            throw new ProviderInitializationError("File '{$filePath}' is not a valid Strapi data file.");
        }

        if ($this->metadata === null) {
            throw new ProviderInitializationError('Could not load metadata from Strapi data file.');
        }
    }

    private function loadMetadata(): void
    {
        $metadata = $this->parseJSONFile(self::METADATA_FILE_PATH);
        $this->metadata = is_array($metadata) ? $metadata : null;
    }

    /** @return array<string, mixed> */
    private function loadAssetMetadata(string $sidecarPath): array
    {
        $filename = (string) preg_replace('/\.json$/', '', Utils::posixBasename($sidecarPath));

        return Utils::validateAssetMetadata($this->parseJSONFile($sidecarPath), $filename);
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
            throw new ProviderInitializationError('Could not load schemas from Strapi data file.');
        }

        // Group schema by UID
        $schemas = [];
        foreach ($schemaCollection as $schema) {
            if (is_array($schema)) {
                $schemas[(string) ($schema['uid'] ?? 'undefined')] = $schema;
            }
        }

        // Transform to valid JSON
        return Schema::schemasToValidJSON($schemas);
    }

    public function validateStage(string $stage): void
    {
        if ($stage !== 'assets') {
            return;
        }

        $uploads = [];
        $sidecars = [];

        try {
            foreach (Parser::entries($this->getBackupStream()) as $entry) {
                if ($entry->type !== 'File') {
                    continue;
                }

                $normalizedPath = Utils::unknownPathToPosix($entry->path);
                $filename = Utils::posixBasename($normalizedPath);

                if (Utils::isFilePathInDirname('assets/uploads', $entry->path)) {
                    $uploads[$filename] = true;
                } elseif (Utils::isFilePathInDirname('assets/metadata', $entry->path)) {
                    try {
                        $contents = $entry->contents();
                    } catch (\Throwable $error) {
                        throw new ProviderValidationError("Asset metadata preflight failed for \"{$filename}\": {$error->getMessage()}", ['error' => $error]);
                    }
                    if (str_ends_with($filename, '.json')) {
                        $sidecars[substr($filename, 0, -strlen('.json'))] = $contents;
                    }
                }
            }
        } catch (ProviderValidationError $error) {
            throw $error;
        }

        $missingSidecars = array_values(array_filter(array_keys($uploads), static fn (string|int $filename): bool => !array_key_exists((string) $filename, $sidecars)));
        sort($missingSidecars);
        if ($missingSidecars !== []) {
            throw new ProviderValidationError(
                'Asset metadata preflight failed: missing sidecar metadata for '
                . implode(', ', array_map(static fn (string|int $filename): string => "\"{$filename}\"", $missingSidecars))
                . '. The destination was not modified; re-export and try again.'
            );
        }

        $metadata = [];
        foreach (array_keys($uploads) as $filename) {
            $filename = (string) $filename;
            try {
                $metadata[$filename] = Utils::validateAssetMetadata(json_decode($sidecars[$filename], true, 512, JSON_THROW_ON_ERROR), $filename);
            } catch (\Throwable $error) {
                throw new ProviderValidationError(
                    "Asset metadata preflight failed for \"{$filename}.json\": " . self::jsonErrorMessage($error) . '. The destination was not modified; re-export and try again.',
                    ['error' => $error]
                );
            }
        }

        $this->assetMetadata = $metadata;
    }

    private static function jsonErrorMessage(\Throwable $error): string
    {
        return $error instanceof \JsonException ? "Unexpected token in JSON: {$error->getMessage()}" : $error->getMessage();
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

        // NOTE: TBD
        return $this->streamJsonlDirectory('configuration');
    }

    /**
     * Assets: `{ metadata, filename, filepath, stats: { size }, stream }` where `stream` yields the
     * file's bytes from the archive (read it before taking the next asset).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function createAssetsReadStream(): \Generator
    {
        $this->reportInfo('creating assets read stream');
        $assetMetadata = $this->assetMetadata;

        foreach (Parser::entries($this->getBackupStream()) as $entry) {
            // find only files in the assets/uploads folder
            if ($entry->type !== 'File' || !Utils::isFilePathInDirname('assets/uploads', $entry->path)) {
                continue;
            }

            $normalizedPath = Utils::unknownPathToPosix($entry->path);
            $file = Utils::posixBasename($normalizedPath);

            $metadata = $assetMetadata[$file] ?? null;
            if ($metadata === null) {
                try {
                    $metadata = $this->loadAssetMetadata("assets/metadata/{$file}.json");
                } catch (\Throwable) {
                    throw new \RuntimeException("Failed to read metadata for {$file}");
                }
            }

            yield [
                'metadata' => $metadata,
                'filename' => $file,
                'filepath' => $normalizedPath,
                'stats' => ['size' => $entry->size],
                'stream' => $entry->chunks(),
            ];
        }
    }

    /**
     * The archive's tar bytes: file → (decipher) → (gunzip).
     *
     * @return \Generator<int, string>
     */
    private function getBackupStream(): \Generator
    {
        ['file' => $file, 'encryption' => $encryption, 'compression' => $compression] = $this->options;

        if (!is_file($file['path']) || !is_readable($file['path'])) {
            throw new \RuntimeException("Could not read backup file path provided at \"{$file['path']}\"");
        }

        $stream = Bytes::readFile($file['path']);

        $key = $encryption['key'] ?? null;
        if (($encryption['enabled'] ?? false) && is_string($key) && $key !== '') {
            $stream = Bytes::cipher($stream, Decrypt::createDecryptionCipher($key));
        }

        if ($compression['enabled'] ?? false) {
            $stream = Bytes::gunzip($stream);
        }

        return $stream;
    }

    /**
     * Stream the JSONL files of a `directory` (posix formatted path) of the archive, one value at a time.
     *
     * @return \Generator<int, mixed>
     */
    private function streamJsonlDirectory(string $directory): \Generator
    {
        foreach (Parser::entries($this->getBackupStream()) as $entry) {
            if ($entry->type !== 'File' || !Utils::isFilePathInDirname($directory, $entry->path)) {
                continue;
            }

            try {
                foreach (Jsonl::parse($entry->chunks()) as $chunk) {
                    yield $chunk;
                }
            } catch (\Throwable $e) {
                throw new ProviderTransferError("Error parsing backup files from backup file {$entry->path}: {$e->getMessage()}", [
                    'details' => [
                        'error' => $e,
                    ],
                ]);
            }
        }
    }

    /**
     * For collecting an entire JSON file then parsing it, not for streaming JSONL
     */
    private function parseJSONFile(string $filePath): mixed
    {
        foreach (Parser::entries($this->getBackupStream()) as $entry) {
            // Filter the parsed entries to only keep the one that matches the given filepath
            if ($entry->type !== 'File' || !Utils::isPathEquivalent($entry->path, $filePath)) {
                continue;
            }

            // Parse from buffer array to string to JSON
            return json_decode($entry->contents(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }

        // If we've parsed all the archive entries, then the file doesn't exist
        throw new \RuntimeException("File \"{$filePath}\" not found");
    }
}
