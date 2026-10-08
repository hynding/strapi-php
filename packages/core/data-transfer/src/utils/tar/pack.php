<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Tar;

/**
 * Not an upstream file: a tar writer producing the same ustar layout as `tar-stream`'s `pack()`
 * (which upstream's file destination uses): 512-byte headers (mode 0644, uid/gid 0, `ustar\0`
 * magic, version `00`), long or non-ASCII names in a `PaxHeader` entry, and two zero blocks at
 * the end. Bytes go to the `$sink` as they are produced.
 */
final class Pack
{
    private const int BLOCK = 512;

    private const int FMODE = 0o644;

    private int $remaining = 0;

    private int $written = 0;

    private bool $inEntry = false;

    private bool $finalized = false;

    /** @param \Closure(string): void $sink */
    public function __construct(private readonly \Closure $sink)
    {
    }

    /**
     * Add a whole entry at once (`pack.entry(header, buffer)`): the size is the content's.
     *
     * @param array{name: string, size?: int, mode?: int, mtime?: int} $header
     */
    public function entry(array $header, string $content): void
    {
        $header['size'] = strlen($content);
        $this->beginEntry($header);
        $this->writeEntry($content);
        $this->endEntry();
    }

    /** @param array{name: string, size?: int, mode?: int, mtime?: int, type?: string} $header */
    public function beginEntry(array $header): void
    {
        if ($this->finalized) {
            throw new \LogicException('already finalized');
        }
        if ($this->inEntry) {
            throw new \LogicException('already piping an entry');
        }

        $size = $header['size'] ?? 0;
        $this->encode($header + ['size' => $size]);

        $this->inEntry = true;
        $this->remaining = $size;
        $this->written = 0;
    }

    public function writeEntry(string $chunk): void
    {
        if (!$this->inEntry) {
            throw new \LogicException('no entry in progress');
        }
        if (strlen($chunk) > $this->remaining) {
            throw new \RuntimeException('size mismatch');
        }

        $this->remaining -= strlen($chunk);
        $this->written += strlen($chunk);
        if ($chunk !== '') {
            ($this->sink)($chunk);
        }
    }

    public function endEntry(): void
    {
        if (!$this->inEntry) {
            return;
        }
        $this->inEntry = false;

        if ($this->remaining !== 0) {
            throw new \RuntimeException('size mismatch');
        }

        $this->overflow($this->written);
    }

    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;
        ($this->sink)(str_repeat("\0", 1024));
    }

    /** @param array{name: string, size: int, mode?: int, mtime?: int, type?: string} $header */
    private function encode(array $header): void
    {
        $header['mode'] ??= self::FMODE;
        $header['mtime'] ??= time();

        $buf = self::encodeHeader($header['name'], $header['mode'], $header['size'], $header['mtime'], '0');
        if ($buf !== null) {
            ($this->sink)($buf);

            return;
        }

        // the name does not fit a ustar header (too long or not ASCII): a pax header carries it
        $pax = self::addLength(' path=' . $header['name'] . "\n");
        ($this->sink)((string) self::encodeHeader('PaxHeader', $header['mode'], strlen($pax), $header['mtime'], 'x'));
        ($this->sink)($pax);
        $this->overflow(strlen($pax));
        ($this->sink)((string) self::encodeHeader('PaxHeader', $header['mode'], $header['size'], $header['mtime'], '0'));
    }

    private function overflow(int $size): void
    {
        $size &= self::BLOCK - 1;
        if ($size !== 0) {
            ($this->sink)(str_repeat("\0", self::BLOCK - $size));
        }
    }

    private static function addLength(string $str): string
    {
        $len = strlen($str);
        $digits = (int) floor(log10($len)) + 1;
        if ($len + $digits >= 10 ** $digits) {
            ++$digits;
        }

        return ($len + $digits) . $str;
    }

    private static function encodeOct(int $val, int $n): string
    {
        $oct = decoct($val);
        if (strlen($oct) > $n) {
            return str_repeat('7', $n) . ' ';
        }

        return str_pad($oct, $n, '0', STR_PAD_LEFT) . ' ';
    }

    private static function encodeHeader(string $name, int $mode, int $size, int $mtime, string $typeflag): ?string
    {
        if (preg_match('/[^\x00-\x7f]/', $name) === 1) {
            return null; // utf-8
        }

        $prefix = '';
        while (strlen($name) > 100) {
            $i = strpos($name, '/');
            if ($i === false) {
                return null;
            }
            $prefix .= ($prefix !== '' ? '/' : '') . substr($name, 0, $i);
            $name = substr($name, $i + 1);
        }

        if (strlen($prefix) > 155) {
            return null;
        }

        $buf = str_repeat("\0", self::BLOCK);
        $put = static function (string $value, int $offset) use (&$buf): void {
            $buf = substr_replace($buf, $value, $offset, strlen($value));
        };

        $put($name, 0);
        $put(self::encodeOct($mode & 0o7777, 6), 100);
        $put(self::encodeOct(0, 6), 108);
        $put(self::encodeOct(0, 6), 116);
        $put(self::encodeOct($size, 11), 124);
        $put(self::encodeOct($mtime, 11), 136);
        $put($typeflag, 156);
        $put("ustar\0", 257);
        $put('00', 263);
        $put(self::encodeOct(0, 6), 329);
        $put(self::encodeOct(0, 6), 337);
        if ($prefix !== '') {
            $put($prefix, 345);
        }

        // checksum: the checksum field counts as eight spaces
        $sum = 8 * 32;
        for ($i = 0; $i < 148; ++$i) {
            $sum += ord($buf[$i]);
        }
        for ($i = 156; $i < self::BLOCK; ++$i) {
            $sum += ord($buf[$i]);
        }
        $put(self::encodeOct($sum, 6), 148);

        return $buf;
    }
}
