<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Directory\Providers\Destination;

use Strapi\DataTransfer\File\Providers\Destination\Utils as FileDestinationUtils;
use Strapi\DataTransfer\Utils\Stream\Writable;

/**
 * Port of src/directory/providers/destination/utils.ts.
 */
final class Utils
{
    /** @return \Closure(int=): string */
    public static function createFilePathFactory(string $type): \Closure
    {
        return FileDestinationUtils::createFilePathFactory($type);
    }

    /**
     * JSONL writer that mirrors {@see FileDestinationUtils::createTarEntryStream()} but writes files under a root directory.
     *
     * @param \Closure(int=): string $pathFactory
     */
    public static function createDirectoryJsonlWriter(string $rootDir, \Closure $pathFactory, int|float|null $maxSize = null): Writable
    {
        return new class ($rootDir, $pathFactory, $maxSize ?? 2.56e8) extends Writable {
            private int $fileIndex = 0;

            private string $buffer = '';

            /** @param \Closure(int=): string $pathFactory */
            public function __construct(private readonly string $rootDir, private readonly \Closure $pathFactory, private readonly int|float $maxSize)
            {
                parent::__construct();
            }

            private function resolvePath(string $posixName): string
            {
                return rtrim($this->rootDir, '/') . '/' . implode('/', explode('/', $posixName));
            }

            private function flush(): void
            {
                if ($this->buffer === '') {
                    return;
                }

                ++$this->fileIndex;
                $name = ($this->pathFactory)($this->fileIndex);
                $targetPath = $this->resolvePath($name);
                $dir = dirname($targetPath);
                if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
                    throw new \RuntimeException("Could not create directory {$dir}");
                }
                if (@file_put_contents($targetPath, $this->buffer) === false) {
                    throw new \RuntimeException("Could not write {$targetPath}");
                }
                $this->buffer = '';
            }

            protected function doWrite(mixed $chunk): void
            {
                $chunk = is_scalar($chunk) ? (string) $chunk : '';
                $size = strlen($chunk);

                if ($size > $this->maxSize) {
                    throw new \RuntimeException("payload too large: {$size}>{$this->maxSize}");
                }

                if (strlen($this->buffer) + $size > $this->maxSize) {
                    $this->flush();
                }

                $this->buffer .= $chunk;
            }

            protected function doFinal(): void
            {
                $this->flush();
            }

            protected function doDestroy(?\Throwable $error): void
            {
                $this->flush();
            }
        };
    }
}
