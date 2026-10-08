<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Stream;

use Strapi\DataTransfer\Utils\Json;

/**
 * Not an upstream file: stream-json's JSONL `parser({ checkErrors: true })` and `stringer()`.
 */
final class Jsonl
{
    /**
     * Parse JSON lines from byte chunks, one value per line.
     *
     * @param iterable<string> $chunks
     *
     * @return \Generator<int, mixed>
     */
    public static function parse(iterable $chunks): \Generator
    {
        $buffer = '';
        foreach ($chunks as $chunk) {
            $buffer .= $chunk;
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $nl);
                $buffer = (string) substr($buffer, $nl + 1);
                if (trim($line) === '') {
                    continue;
                }
                yield self::decode($line);
            }
        }

        if (trim($buffer) !== '') {
            yield self::decode($buffer);
        }
    }

    /** A value as a JSONL line (`JSON.stringify(value) + '\n'`). */
    public static function stringify(mixed $value): string
    {
        return Json::stringify($value) . "\n";
    }

    private static function decode(string $line): mixed
    {
        try {
            return json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Unexpected token in JSON: {$e->getMessage()}", 0, $e);
        }
    }
}
