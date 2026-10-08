<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Directory\Providers\Destination;

use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Types\Providers\IDestinationProvider;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Json;
use Strapi\DataTransfer\Utils\Stream\Jsonl;
use Strapi\DataTransfer\Utils\Stream\Writable;

/**
 * Port of src/directory/providers/destination/index.ts: `createLocalDirectoryDestinationProvider()`,
 * an unpacked export (the archive layout as a directory).
 *
 * @phpstan-type DirectoryDestinationOptions array{directory: array{path: string}, file?: array{maxSizeJsonl?: int|float|null}}
 */
final class Destination implements IDestinationProvider
{
    public string $name = 'destination::local-directory';

    public string $type = 'destination';

    /** @var DirectoryDestinationOptions */
    public array $options;

    /** @var array{file?: array{path?: string}} */
    public array $results = [];

    /** @var array{source?: array<string, mixed>, destination?: array<string, mixed>} */
    private array $providersMetadata = [];

    private string $rootResolved;

    private ?Diagnostic $diagnostics = null;

    private bool $rolledBack = false;

    /** @param DirectoryDestinationOptions $options */
    public function __construct(array $options)
    {
        $this->options = $options;
        $this->rootResolved = self::resolve($options['directory']['path']);
    }

    /** @param DirectoryDestinationOptions $options */
    public static function createLocalDirectoryDestinationProvider(array $options): self
    {
        return new self($options);
    }

    /** node `path.resolve()` of one path */
    public static function resolve(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = (getcwd() ?: '.') . '/' . $path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    private function reportInfo(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'directory-destination-provider',
            ],
            'kind' => 'info',
        ]);
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
        $this->rolledBack = false;
        $this->reportInfo('preparing directory export');
        if (!is_dir($this->rootResolved) && !@mkdir($this->rootResolved, 0o777, true) && !is_dir($this->rootResolved)) {
            throw new \RuntimeException("EACCES: could not create directory '{$this->rootResolved}'");
        }
        $this->results['file'] = ['path' => $this->rootResolved];
    }

    public function close(): void
    {
        if ($this->rolledBack) {
            return;
        }

        $this->writeMetadata();
    }

    public function rollback(?\Throwable $e = null): void
    {
        $this->rolledBack = true;
        $this->reportInfo('rolling back');
        self::rmrf($this->rootResolved);
    }

    public static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            self::rmrf($path . '/' . $item);
        }
        @rmdir($path);
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
            $target = $this->rootResolved . '/metadata.json';
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0o777, true);
            }
            file_put_contents($target, Json::stringify($metadata, true));
        }
    }

    private function jsonlWriteStream(string $type): Writable
    {
        $this->reportInfo("creating {$type} write stream");
        $entryStream = Utils::createDirectoryJsonlWriter($this->rootResolved, Utils::createFilePathFactory($type), $this->options['file']['maxSizeJsonl'] ?? null);

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
        $this->reportInfo('creating assets write stream');
        $root = $this->rootResolved;

        return new Writable(write: static function (mixed $data) use ($root): void {
            $filename = is_array($data) && is_string($data['filename'] ?? null) ? $data['filename'] : '';
            $entryPath = "{$root}/assets/uploads/{$filename}";
            $entryMetadataPath = "{$root}/assets/metadata/{$filename}.json";

            $assetWriteError = static fn (\Throwable|string $cause): ProviderTransferError => new ProviderTransferError("Failed to write asset {$filename}", [
                'details' => [
                    'error' => $cause instanceof \Throwable ? $cause : new \RuntimeException($cause),
                ],
            ]);

            foreach ([dirname($entryPath), dirname($entryMetadataPath)] as $dir) {
                if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
                    throw $assetWriteError("Could not create {$dir}");
                }
            }

            if (@file_put_contents($entryMetadataPath, Json::stringify($data['metadata'] ?? null)) === false) {
                throw $assetWriteError("Could not write {$entryMetadataPath}");
            }

            $fileStream = @fopen($entryPath, 'wb');
            if ($fileStream === false) {
                throw $assetWriteError("Could not open {$entryPath}");
            }

            try {
                foreach ($data['stream'] ?? [] as $chunk) {
                    if (@fwrite($fileStream, (string) $chunk) === false) {
                        throw new \RuntimeException("Could not write {$entryPath}");
                    }
                }
            } catch (\Throwable $error) {
                throw $assetWriteError($error);
            } finally {
                fclose($fileStream);
            }
        });
    }
}
