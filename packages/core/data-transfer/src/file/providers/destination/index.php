<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\File\Providers\Destination;

use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Types\Providers\IDestinationProvider;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Encryption\Encrypt;
use Strapi\DataTransfer\Utils\Json;
use Strapi\DataTransfer\Utils\Stream\Bytes;
use Strapi\DataTransfer\Utils\Stream\Jsonl;
use Strapi\DataTransfer\Utils\Stream\Writable;
use Strapi\DataTransfer\Utils\Tar\Pack;

/**
 * Port of src/file/providers/destination/index.ts: `createLocalFileDestinationProvider()`, the
 * destination that writes a Strapi export archive: a tar (`tar-stream` layout) of
 * `metadata.json`, `{schemas,entities,links,configuration}/*_NNNNN.jsonl`,
 * `assets/uploads/<file>` and `assets/metadata/<file>.json`, optionally gzipped, then encrypted
 * (`aes-128-ecb`, scrypt-derived key), as `<path>.tar[.gz][.enc]`.
 *
 * @phpstan-type FileDestinationOptions array{encryption: array{enabled: bool, key?: string|null}, compression: array{enabled: bool}, file: array{path: string, maxSize?: int|null, maxSizeJsonl?: int|float|null}}
 */
final class Destination implements IDestinationProvider
{
    public string $name = 'destination::local-file';

    public string $type = 'destination';

    /** @var FileDestinationOptions */
    public array $options;

    /** @var array{file?: array{path?: string}} */
    public array $results = [];

    /** @var array{source?: array<string, mixed>, destination?: array<string, mixed>} */
    private array $providersMetadata = [];

    private ?Pack $archive = null;

    /** @var (\Closure(): void)|null */
    private ?\Closure $closeArchive = null;

    private ?Diagnostic $diagnostics = null;

    private bool $closed = false;

    /** @param FileDestinationOptions $options */
    public function __construct(array $options)
    {
        $this->options = $options;
    }

    /** @param FileDestinationOptions $options */
    public static function createLocalFileDestinationProvider(array $options): self
    {
        return new self($options);
    }

    private function reportInfo(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'file-destination-provider',
            ],
            'kind' => 'info',
        ]);
    }

    private function archivePath(): string
    {
        ['encryption' => $encryption, 'compression' => $compression, 'file' => $file] = $this->options;

        $filePath = "{$file['path']}.tar";

        if ($compression['enabled'] ?? false) {
            $filePath .= '.gz';
        }

        if ($encryption['enabled'] ?? false) {
            $filePath .= '.enc';
        }

        return $filePath;
    }

    /** @param array<string, mixed> $metadata */
    public function setMetadata(string $target, array $metadata): self
    {
        $this->providersMetadata[$target] = $metadata;

        return $this;
    }

    public function bootstrap(?Diagnostic $diagnostics = null): void
    {
        $this->diagnostics = $diagnostics;
        $this->closed = false;
        ['compression' => $compression, 'encryption' => $encryption] = $this->options;

        $key = $encryption['key'] ?? null;
        if (($encryption['enabled'] ?? false) && ($key === null || $key === '')) {
            throw new \RuntimeException("Can't encrypt without a key");
        }

        $outStream = @fopen($this->archivePath(), 'wb');
        if ($outStream === false) {
            $error = error_get_last();
            throw new \RuntimeException($error['message'] ?? "Could not open {$this->archivePath()}");
        }

        if ($compression['enabled'] ?? false) {
            $this->reportInfo('creating gzip');
        }

        $cipher = ($encryption['enabled'] ?? false) && is_string($key) && $key !== '' ? Encrypt::createEncryptionCipher($key) : null;

        [$write, $close] = Bytes::createWriter($outStream, (bool) ($compression['enabled'] ?? false), $cipher);

        $this->archive = new Pack(static function (string $bytes) use ($write): void {
            try {
                $write($bytes);
            } catch (\RuntimeException $err) {
                if (str_starts_with($err->getMessage(), 'ENOSPC')) {
                    throw new ProviderTransferError("Your server doesn't have space to proceed with the import.");
                }
                throw $err;
            }
        });
        $this->closeArchive = $close;

        $this->results['file'] = ['path' => $this->archivePath()];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $archive = $this->archive;
        if ($archive === null) {
            return;
        }

        $this->writeMetadata();
        $archive->finalize();

        if ($this->closeArchive !== null) {
            ($this->closeArchive)();
            $this->closeArchive = null;
        }
    }

    public function rollback(?\Throwable $e = null): void
    {
        $this->reportInfo('rolling back');
        $this->close();
        if (is_file($this->archivePath())) {
            @unlink($this->archivePath());
        }
    }

    public function getMetadata(): ?array
    {
        return null;
    }

    private function writeMetadata(): void
    {
        $this->reportInfo('writing metadata');
        $metadata = $this->providersMetadata['source'] ?? null;

        if ($metadata !== null) {
            $this->getArchive()->entry(['name' => 'metadata.json'], Json::stringify($metadata, true));
        }
    }

    private function getArchive(): Pack
    {
        return $this->archive ?? throw new \RuntimeException('Archive stream is unavailable');
    }

    private function jsonlWriteStream(string $type): Writable
    {
        $archive = $this->getArchive();
        $this->reportInfo("creating {$type} write stream");
        $filePathFactory = Utils::createFilePathFactory($type);

        $entryStream = Utils::createTarEntryStream($archive, $filePathFactory, $this->options['file']['maxSizeJsonl'] ?? null);

        // chain([stringer(), entryStream])
        return new Writable(
            write: static function (mixed $chunk) use ($entryStream): void {
                $entryStream->write(Jsonl::stringify($chunk));
            },
            final: static function () use ($entryStream): void {
                $entryStream->end();
            },
            destroy: static function (?\Throwable $error) use ($entryStream): void {
                $entryStream->destroy($error);
            },
        );
    }

    public function createSchemasWriteStream(): Writable
    {
        return $this->jsonlWriteStream('schemas');
    }

    public function createEntitiesWriteStream(): Writable
    {
        return $this->jsonlWriteStream('entities');
    }

    public function createLinksWriteStream(): Writable
    {
        return $this->jsonlWriteStream('links');
    }

    public function createConfigurationWriteStream(): Writable
    {
        return $this->jsonlWriteStream('configuration');
    }

    public function createAssetsWriteStream(): Writable
    {
        $archiveStream = $this->getArchive();

        $this->reportInfo('creating assets write stream');

        return new Writable(write: static function (mixed $data) use ($archiveStream): void {
            if (!is_array($data) || !is_string($data['filename'] ?? null)) {
                throw new \RuntimeException('Invalid asset');
            }

            // always write tar files with posix paths so we have a standard format for paths regardless of system
            $entryPath = 'assets/uploads/' . $data['filename'];

            $entryMetadataPath = 'assets/metadata/' . $data['filename'] . '.json';
            $archiveStream->entry(['name' => $entryMetadataPath], Json::stringify($data['metadata'] ?? null));

            $size = (int) ($data['stats']['size'] ?? 0);
            $archiveStream->beginEntry(['name' => $entryPath, 'size' => $size]);

            try {
                foreach ($data['stream'] ?? [] as $chunk) {
                    $archiveStream->writeEntry((string) $chunk);
                }
                $archiveStream->endEntry();
            } catch (\Throwable $error) {
                if ($error->getMessage() === 'size mismatch') {
                    throw new \RuntimeException("Failed to created an asset tar entry for {$entryPath}: size mismatch", 0, $error);
                }
                throw $error;
            }
        });
    }
}
