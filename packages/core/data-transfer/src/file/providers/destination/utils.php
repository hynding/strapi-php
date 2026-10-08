<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\File\Providers\Destination;

use Strapi\DataTransfer\Utils\Stream\Writable;
use Strapi\DataTransfer\Utils\Tar\Pack;

/**
 * Port of src/file/providers/destination/utils.ts.
 */
final class Utils
{
    /**
     * Create a file path factory for a given path & prefix.
     * Upon being called, the factory will return a file path for a given index
     *
     * @return \Closure(int=): string
     */
    public static function createFilePathFactory(string $type): \Closure
    {
        // always write tar files with posix paths so we have a standard format for paths regardless of system
        return static fn (int $fileIndex = 0): string => $type . '/' . $type . '_' . str_pad((string) $fileIndex, 5, '0', STR_PAD_LEFT) . '.jsonl';
    }

    /**
     * A writable of JSONL lines that buffers them and writes them as tar entries of at most
     * `$maxSize` bytes (`{type}/{type}_00001.jsonl`, `_00002`, …).
     *
     * @param \Closure(int=): string $pathFactory
     */
    public static function createTarEntryStream(Pack $archive, \Closure $pathFactory, int|float|null $maxSize = null): Writable
    {
        return new class ($archive, $pathFactory, $maxSize ?? 2.56e8) extends Writable {
            private int $fileIndex = 0;

            private string $buffer = '';

            /** @param \Closure(int=): string $pathFactory */
            public function __construct(private readonly Pack $archive, private readonly \Closure $pathFactory, private readonly int|float $maxSize)
            {
                parent::__construct();
            }

            private function flush(): void
            {
                if ($this->buffer === '') {
                    return;
                }

                ++$this->fileIndex;
                $name = ($this->pathFactory)($this->fileIndex);

                $this->archive->entry(['name' => $name], $this->buffer);

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

            // upstream flushes in `destroy`, which Node runs after `end()` too (autoDestroy)
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
