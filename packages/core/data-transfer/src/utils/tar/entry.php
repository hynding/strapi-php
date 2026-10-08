<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Tar;

/**
 * Not an upstream file: one entry read by {@see Parser} (node-tar's `ReadEntry`): its `path`,
 * `type` (`File`, `Directory`, `SymbolicLink`, …), `size`, and its content, read lazily from the
 * archive. The content must be read before the parser moves on (what is left is skipped).
 */
final class Entry
{
    private int $remaining;

    /** @param \Closure(int): string $read reads up to n bytes of the archive */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly int $size,
        public readonly int $mode,
        public readonly int $mtime,
        private readonly \Closure $read,
    ) {
        $this->remaining = $size;
    }

    /** @return \Generator<int, string> */
    public function chunks(int $chunkSize = 65536): \Generator
    {
        while ($this->remaining > 0) {
            $chunk = ($this->read)(min($chunkSize, $this->remaining));
            if ($chunk === '') {
                throw new \RuntimeException("Unexpected end of archive while reading {$this->path}");
            }
            $this->remaining -= strlen($chunk);
            yield $chunk;
        }
    }

    public function contents(): string
    {
        $contents = '';
        foreach ($this->chunks() as $chunk) {
            $contents .= $chunk;
        }

        return $contents;
    }

    /** bytes of the content not read yet */
    public function remaining(): int
    {
        return $this->remaining;
    }

    /** @internal the parser skips what the consumer left */
    public function markConsumed(): void
    {
        $this->remaining = 0;
    }
}
