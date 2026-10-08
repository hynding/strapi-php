<?php

declare(strict_types=1);

namespace Strapi\Upload\Utils;

/**
 * PHP-port addition: lossless Huffman-table optimisation of a baseline JPEG (what
 * `jpegtran -optimize` does), standing in for sharp's `optimiseCoding: true` default.
 *
 * GD writes JPEGs with libjpeg's standard Huffman tables, a JFIF header and a `CREATOR: gd-jpeg`
 * comment; sharp (libvips) writes optimal tables and neither header nor comment. Re-coding GD's output this way keeps the
 * pixels and gives the sizes sharp gives for the same quality. Anything this does not handle
 * (progressive or arithmetic coding, restart markers…) is returned unchanged.
 */
final class JpegOptimizer
{
    public static function optimize(string $jpeg): string
    {
        try {
            return self::recode($jpeg) ?? $jpeg;
        } catch (\Throwable) {
            return $jpeg;
        }
    }

    private static function recode(string $data): ?string
    {
        $len = strlen($data);
        if ($len < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        $pos = 2;
        $keep = []; // segments written back as they are (APPn, DQT, SOF0)
        $dc = [];
        $ac = [];
        $frame = null;
        $scan = null;

        while ($pos + 4 <= $len) {
            if ($data[$pos] !== "\xFF") {
                return null;
            }
            $marker = ord($data[$pos + 1]);
            if ($marker === 0xFF) {
                $pos++;
                continue;
            }
            $segLen = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
            $segment = substr($data, $pos, 2 + $segLen);
            $body = substr($data, $pos + 4, $segLen - 2);
            $pos += 2 + $segLen;

            if ($marker === 0xC4) {
                $p = 0;
                while ($p < strlen($body)) {
                    $tc = ord($body[$p]) >> 4;
                    $th = ord($body[$p]) & 0x0F;
                    $bits = [];
                    $count = 0;
                    for ($i = 1; $i <= 16; $i++) {
                        $bits[$i] = ord($body[$p + $i]);
                        $count += $bits[$i];
                    }
                    $vals = array_values(unpack('C*', substr($body, $p + 17, $count)) ?: []);
                    $table = self::decodeTable($bits, $vals);
                    if ($tc === 0) {
                        $dc[$th] = $table;
                    } else {
                        $ac[$th] = $table;
                    }
                    $p += 17 + $count;
                }
            } elseif ($marker === 0xC0) {
                $frame = [
                    'height' => (ord($body[1]) << 8) | ord($body[2]),
                    'width' => (ord($body[3]) << 8) | ord($body[4]),
                    'components' => [],
                ];
                $n = ord($body[5]);
                for ($i = 0; $i < $n; $i++) {
                    $frame['components'][ord($body[6 + $i * 3])] = [
                        'h' => ord($body[7 + $i * 3]) >> 4,
                        'v' => ord($body[7 + $i * 3]) & 0x0F,
                    ];
                }
                $keep[] = $segment;
            } elseif ($marker === 0xDA) {
                $n = ord($body[0]);
                $components = [];
                for ($i = 0; $i < $n; $i++) {
                    $components[] = ['id' => ord($body[1 + $i * 2]), 'td' => ord($body[2 + $i * 2]) >> 4, 'ta' => ord($body[2 + $i * 2]) & 0x0F];
                }
                $scan = ['components' => $components, 'tail' => substr($body, 1 + $n * 2)];
                break;
            } elseif ($marker === 0xFE || ($marker === 0xE0 && str_starts_with($body, "JFIF\0"))) {
                // COM (GD's "CREATOR: gd-jpeg" comment) and the JFIF APP0 header: sharp writes neither
            } elseif (($marker >= 0xC1 && $marker <= 0xCF) || $marker === 0xDD) {
                return null; // not baseline Huffman, or restart intervals
            } else {
                $keep[] = $segment;
            }
        }

        if ($frame === null || $scan === null) {
            return null;
        }

        // entropy-coded data up to EOI, unstuffed
        $end = strrpos($data, "\xFF\xD9");
        if ($end === false || $end < $pos) {
            return null;
        }
        $coded = substr($data, $pos, $end - $pos);
        if (preg_match('/\xFF[\xD0-\xD7]/', $coded) === 1) {
            return null;
        }
        $coded = str_replace("\xFF\x00", "\xFF", $coded);

        if ($frame['components'] === []) {
            return null;
        }
        $hmax = max(1, ...array_column($frame['components'], 'h'));
        $vmax = max(1, ...array_column($frame['components'], 'v'));

        $blocks = [];
        foreach ($scan['components'] as $component) {
            $c = $frame['components'][$component['id']] ?? null;
            if ($c === null) {
                return null;
            }
            $blocks[] = ['h' => $c['h'], 'v' => $c['v'], 'td' => $component['td'], 'ta' => $component['ta']];
        }

        if (count($blocks) === 1) {
            $c = $blocks[0];
            $compWidth = (int) ceil($frame['width'] * $c['h'] / $hmax);
            $compHeight = (int) ceil($frame['height'] * $c['v'] / $vmax);
            $mcus = (int) (ceil($compWidth / 8) * ceil($compHeight / 8));
            $blocks[0]['h'] = 1;
            $blocks[0]['v'] = 1;
        } else {
            $mcus = (int) (ceil($frame['width'] / (8 * $hmax)) * ceil($frame['height'] / (8 * $vmax)));
        }

        // decode into (class, table, symbol, extraBits, extraLength) events
        $reader = new class ($coded) {
            private int $byte = 0;

            private int $bit = 0;

            public function __construct(private readonly string $data)
            {
            }

            public function readBit(): int
            {
                if ($this->byte >= strlen($this->data)) {
                    throw new \RuntimeException('Unexpected end of data');
                }
                $value = (ord($this->data[$this->byte]) >> (7 - $this->bit)) & 1;
                if (++$this->bit === 8) {
                    $this->bit = 0;
                    $this->byte++;
                }

                return $value;
            }

            public function readBits(int $n): int
            {
                $value = 0;
                for ($i = 0; $i < $n; $i++) {
                    $value = ($value << 1) | $this->readBit();
                }

                return $value;
            }

            /** @param array<string, int> $table */
            public function decode(array $table): int
            {
                $code = 0;
                for ($length = 1; $length <= 16; $length++) {
                    $code = ($code << 1) | $this->readBit();
                    $key = "{$length}:{$code}";
                    if (isset($table[$key])) {
                        return $table[$key];
                    }
                }

                throw new \RuntimeException('Bad Huffman code');
            }
        };

        $events = [];
        $freqDc = [];
        $freqAc = [];
        for ($m = 0; $m < $mcus; $m++) {
            foreach ($blocks as $block) {
                $dcTable = $dc[$block['td']] ?? null;
                $acTable = $ac[$block['ta']] ?? null;
                if ($dcTable === null || $acTable === null) {
                    return null;
                }
                for ($b = 0; $b < $block['h'] * $block['v']; $b++) {
                    $s = $reader->decode($dcTable);
                    $events[] = [0, $block['td'], $s, $reader->readBits($s), $s];
                    $freqDc[$block['td']][$s] = ($freqDc[$block['td']][$s] ?? 0) + 1;

                    for ($k = 1; $k < 64;) {
                        $rs = $reader->decode($acTable);
                        $size = $rs & 0x0F;
                        $run = $rs >> 4;
                        $events[] = [1, $block['ta'], $rs, $size > 0 ? $reader->readBits($size) : 0, $size];
                        $freqAc[$block['ta']][$rs] = ($freqAc[$block['ta']][$rs] ?? 0) + 1;
                        if ($size === 0) {
                            if ($run !== 15) {
                                break; // EOB
                            }
                            $k += 16;
                            continue;
                        }
                        $k += $run + 1;
                    }
                }
            }
        }

        // optimal tables
        $dht = '';
        $codes = [0 => [], 1 => []];
        foreach ([0 => $freqDc, 1 => $freqAc] as $class => $tables) {
            ksort($tables);
            foreach ($tables as $id => $freq) {
                [$bits, $vals] = self::genOptimalTable($freq);
                // one DHT marker per table, as libjpeg writes them
                $table = chr(($class << 4) | $id);
                for ($i = 1; $i <= 16; $i++) {
                    $table .= chr($bits[$i]);
                }
                $table .= implode('', array_map(chr(...), $vals));
                $dht .= "\xFF\xC4" . pack('n', strlen($table) + 2) . $table;
                $codes[$class][$id] = self::encodeTable($bits, $vals);
            }
        }

        // re-encode
        $out = '';
        $acc = 0;
        $accBits = 0;
        $put = static function (int $value, int $length) use (&$out, &$acc, &$accBits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $acc = ($acc << 1) | (($value >> $i) & 1);
                if (++$accBits === 8) {
                    $out .= chr($acc);
                    if ($acc === 0xFF) {
                        $out .= "\x00";
                    }
                    $acc = 0;
                    $accBits = 0;
                }
            }
        };
        foreach ($events as [$class, $id, $symbol, $extra, $extraLength]) {
            $entry = $codes[$class][$id][$symbol] ?? null;
            if ($entry === null) {
                return null;
            }
            [$code, $length] = $entry;
            $put($code, $length);
            if ($extraLength > 0) {
                $put($extra, $extraLength);
            }
        }
        if ($accBits > 0) {
            $put((1 << (8 - $accBits)) - 1, 8 - $accBits);
        }

        $sos = chr(count($scan['components']));
        foreach ($scan['components'] as $component) {
            $sos .= chr($component['id']) . chr(($component['td'] << 4) | $component['ta']);
        }
        $sos .= $scan['tail'];

        return "\xFF\xD8"
            . implode('', $keep)
            . $dht
            . "\xFF\xDA" . pack('n', strlen($sos) + 2) . $sos
            . $out
            . "\xFF\xD9";
    }

    /**
     * @param array<int, int> $bits
     * @param list<int> $vals
     * @return array<string, int> "length:code" => symbol
     */
    private static function decodeTable(array $bits, array $vals): array
    {
        $table = [];
        foreach (self::encodeTable($bits, $vals) as $symbol => [$code, $length]) {
            $table["{$length}:{$code}"] = $symbol;
        }

        return $table;
    }

    /**
     * Canonical codes.
     *
     * @param array<int, int> $bits
     * @param list<int> $vals
     * @return array<int, array{0: int, 1: int}> symbol => [code, length]
     */
    private static function encodeTable(array $bits, array $vals): array
    {
        $codes = [];
        $code = 0;
        $k = 0;
        for ($length = 1; $length <= 16; $length++) {
            for ($i = 0; $i < $bits[$length]; $i++) {
                $codes[$vals[$k++]] = [$code, $length];
                $code++;
            }
            $code <<= 1;
        }

        return $codes;
    }

    /**
     * libjpeg's `jpeg_gen_optimal_table()`.
     *
     * @param array<int, int> $frequencies
     * @return array{0: array<int, int>, 1: list<int>}
     */
    private static function genOptimalTable(array $frequencies): array
    {
        $freq = array_fill(0, 257, 0);
        foreach ($frequencies as $symbol => $count) {
            $freq[$symbol] = $count;
        }
        $freq[256] = 1; // make sure 256 has a nonzero count
        $codesize = array_fill(0, 257, 0);
        $others = array_fill(0, 257, -1);

        while (true) {
            $c1 = -1;
            $v = 1000000000;
            for ($i = 0; $i <= 256; $i++) {
                if ($freq[$i] && $freq[$i] <= $v) {
                    $v = $freq[$i];
                    $c1 = $i;
                }
            }
            $c2 = -1;
            $v = 1000000000;
            for ($i = 0; $i <= 256; $i++) {
                if ($freq[$i] && $freq[$i] <= $v && $i !== $c1) {
                    $v = $freq[$i];
                    $c2 = $i;
                }
            }
            if ($c2 < 0) {
                break;
            }

            $freq[$c1] += $freq[$c2];
            $freq[$c2] = 0;

            $codesize[$c1]++;
            while ($others[$c1] >= 0) {
                $c1 = $others[$c1];
                $codesize[$c1]++;
            }
            $others[$c1] = $c2;

            $codesize[$c2]++;
            while ($others[$c2] >= 0) {
                $c2 = $others[$c2];
                $codesize[$c2]++;
            }
        }

        $bits = array_fill(0, 33, 0);
        for ($i = 0; $i <= 256; $i++) {
            if ($codesize[$i]) {
                if ($codesize[$i] > 32) {
                    throw new \RuntimeException('Huffman code size table overflow');
                }
                $bits[$codesize[$i]]++;
            }
        }

        for ($i = 32; $i > 16; $i--) {
            while ($bits[$i] > 0) {
                $j = $i - 2;
                while ($bits[$j] === 0) {
                    $j--;
                }
                $bits[$i] -= 2;
                $bits[$i - 1]++;
                $bits[$j + 1] += 2;
                $bits[$j]--;
            }
        }

        $i = 16;
        while ($bits[$i] === 0) {
            $i--;
        }
        $bits[$i]--;

        $vals = [];
        for ($i = 1; $i <= 32; $i++) {
            for ($j = 0; $j <= 255; $j++) {
                if ($codesize[$j] === $i) {
                    $vals[] = $j;
                }
            }
        }

        return [array_slice($bits, 0, 17, true), $vals];
    }
}
