<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Tar;

/**
 * Not an upstream file: a streaming tar reader (what upstream gets from node-tar's `Parser`).
 * Reads ustar/GNU/pax archives from an iterable of byte chunks, honors pax `path` records and
 * GNU long names, and yields one {@see Entry} at a time.
 */
final class Parser
{
    private const int BLOCK = 512;

    private string $buffer = '';

    private bool $eof = false;

    /** @var \Iterator<mixed, string> */
    private \Iterator $source;

    /** @param iterable<string> $source */
    private function __construct(iterable $source)
    {
        $this->source = (static function () use ($source): \Generator {
            yield from $source;
        })();
        $this->source->rewind();
    }

    /**
     * @param iterable<string> $source
     *
     * @return \Generator<int, Entry>
     */
    public static function entries(iterable $source): \Generator
    {
        $parser = new self($source);

        yield from $parser->run();
    }

    /** @return \Generator<int, Entry> */
    private function run(): \Generator
    {
        $paxPath = null;
        $globalPaxPath = null;
        $longName = null;

        while (true) {
            $header = $this->read(self::BLOCK);
            if ($header === '') {
                return; // end of stream without the end-of-archive blocks
            }
            if (strlen($header) < self::BLOCK) {
                throw new \RuntimeException('TAR_BAD_ARCHIVE: Unrecognized archive format');
            }
            if ($header === str_repeat("\0", self::BLOCK)) {
                return; // end-of-archive marker
            }

            if (!self::checksumMatches($header)) {
                throw new \RuntimeException('TAR_ENTRY_INVALID: checksum failure');
            }

            $typeflag = $header[156];
            $size = self::decodeOct($header, 124, 12);
            $mode = self::decodeOct($header, 100, 8);
            $mtime = self::decodeOct($header, 136, 12);
            $name = self::decodeStr($header, 0, 100);
            if (substr($header, 257, 5) === 'ustar') {
                $prefix = self::decodeStr($header, 345, 155);
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
            }

            switch ($typeflag) {
                case 'x': // pax extended header for the next entry
                    $records = self::decodePax($this->readEntryBytes($size));
                    $paxPath = $records['path'] ?? $paxPath;
                    continue 2;
                case 'g': // global pax header
                    $records = self::decodePax($this->readEntryBytes($size));
                    $globalPaxPath = $records['path'] ?? $globalPaxPath;
                    continue 2;
                case 'L': // GNU long name
                    $longName = rtrim($this->readEntryBytes($size), "\0");
                    continue 2;
                case 'K': // GNU long link name
                    $this->readEntryBytes($size);
                    continue 2;
            }

            $path = $longName ?? $paxPath ?? $globalPaxPath ?? $name;
            $longName = null;
            $paxPath = null;

            $entry = new Entry($path, self::typeName($typeflag), $size, $mode, $mtime, fn (int $n): string => $this->read($n));

            yield $entry;

            // skip what the consumer did not read, then the padding
            $left = $entry->remaining();
            $entry->markConsumed();
            $this->skip($left + self::padding($size));
        }
    }

    private function readEntryBytes(int $size): string
    {
        $data = $this->read($size);
        $this->skip(self::padding($size));

        return $data;
    }

    private static function padding(int $size): int
    {
        $rest = $size % self::BLOCK;

        return $rest === 0 ? 0 : self::BLOCK - $rest;
    }

    /** Read up to `$n` bytes (fewer only at the end of the stream). */
    private function read(int $n): string
    {
        while (strlen($this->buffer) < $n && !$this->eof) {
            if (!$this->source->valid()) {
                $this->eof = true;
                break;
            }
            $this->buffer .= (string) $this->source->current();
            $this->source->next();
        }

        $out = (string) substr($this->buffer, 0, $n);
        $this->buffer = (string) substr($this->buffer, strlen($out));

        return $out;
    }

    private function skip(int $n): void
    {
        while ($n > 0) {
            $chunk = $this->read(min($n, 1 << 20));
            if ($chunk === '') {
                return;
            }
            $n -= strlen($chunk);
        }
    }

    private static function checksumMatches(string $header): bool
    {
        $expected = self::decodeOct($header, 148, 8);
        $sum = 8 * 32;
        for ($i = 0; $i < 148; ++$i) {
            $sum += ord($header[$i]);
        }
        for ($i = 156; $i < self::BLOCK; ++$i) {
            $sum += ord($header[$i]);
        }

        return $sum === $expected;
    }

    private static function decodeOct(string $buf, int $offset, int $length): int
    {
        $val = substr($buf, $offset, $length);

        // base-256 (GNU) for big values
        if ((ord($val[0]) & 0x80) !== 0) {
            $sum = 0;
            for ($i = 1; $i < $length; ++$i) {
                $sum = $sum * 256 + ord($val[$i]);
            }

            return $sum;
        }

        $val = trim($val, " \0");

        return $val === '' ? 0 : (int) octdec($val);
    }

    private static function decodeStr(string $buf, int $offset, int $length): string
    {
        $val = substr($buf, $offset, $length);
        $nul = strpos($val, "\0");

        return $nul === false ? $val : substr($val, 0, $nul);
    }

    /** @return array<string, string> */
    private static function decodePax(string $buf): array
    {
        $result = [];
        while ($buf !== '') {
            $space = strpos($buf, ' ');
            if ($space === false) {
                break;
            }
            $len = (int) substr($buf, 0, $space);
            if ($len <= 0) {
                break;
            }
            $record = substr($buf, $space + 1, $len - $space - 2);
            $eq = strpos($record, '=');
            if ($eq === false) {
                break;
            }
            $result[substr($record, 0, $eq)] = substr($record, $eq + 1);
            $buf = (string) substr($buf, $len);
        }

        return $result;
    }

    private static function typeName(string $flag): string
    {
        return match ($flag) {
            '0', "\0", '7' => 'File',
            '1' => 'Link',
            '2' => 'SymbolicLink',
            '3' => 'CharacterDevice',
            '4' => 'BlockDevice',
            '5' => 'Directory',
            '6' => 'FIFO',
            default => 'Unsupported',
        };
    }
}
