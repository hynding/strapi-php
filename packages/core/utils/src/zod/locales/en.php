<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod\Locales;

use Strapi\Utils\Zod\Undefined;
use Strapi\Utils\Zod\Util;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * zod's default English error map (`zod/v4/locales/en.js`), message for message.
 */
final class En
{
    private const array SIZABLE = [
        'string' => 'characters',
        'file' => 'bytes',
        'array' => 'items',
        'set' => 'items',
        'map' => 'entries',
    ];

    private const array FORMATS = [
        'regex' => 'input',
        'email' => 'email address',
        'url' => 'URL',
        'emoji' => 'emoji',
        'uuid' => 'UUID',
        'uuidv4' => 'UUIDv4',
        'uuidv6' => 'UUIDv6',
        'nanoid' => 'nanoid',
        'guid' => 'GUID',
        'cuid' => 'cuid',
        'cuid2' => 'cuid2',
        'ulid' => 'ULID',
        'xid' => 'XID',
        'ksuid' => 'KSUID',
        'datetime' => 'ISO datetime',
        'date' => 'ISO date',
        'time' => 'ISO time',
        'duration' => 'ISO duration',
        'ipv4' => 'IPv4 address',
        'ipv6' => 'IPv6 address',
        'mac' => 'MAC address',
        'cidrv4' => 'IPv4 range',
        'cidrv6' => 'IPv6 range',
        'base64' => 'base64-encoded string',
        'base64url' => 'base64url-encoded string',
        'json_string' => 'JSON string',
        'e164' => 'E.164 number',
        'jwt' => 'JWT',
        'template_literal' => 'input',
    ];

    /** @param array<string, mixed> $issue */
    public static function message(array $issue): string
    {
        $str = static fn (mixed $v): string => Util::jsString($v);

        switch ($issue['code'] ?? null) {
            case 'invalid_type':
                $expected = $issue['expected'] ?? '';
                $expected = $expected === 'nan' ? 'NaN' : $str($expected);
                $received = Util::parsedType(array_key_exists('input', $issue) ? $issue['input'] : Undefined::Value);
                $received = $received === 'nan' ? 'NaN' : $received;

                return "Invalid input: expected {$expected}, received {$received}";

            case 'invalid_value':
                /** @var list<mixed> $values */
                $values = $issue['values'] ?? [];
                if (count($values) === 1) {
                    return 'Invalid input: expected ' . Util::stringifyPrimitive($values[0]);
                }

                return 'Invalid option: expected one of ' . Util::joinValues($values, '|');

            case 'too_big':
                $adj = ($issue['inclusive'] ?? false) ? '<=' : '<';
                $origin = $issue['origin'] ?? null;
                $unit = is_string($origin) ? (self::SIZABLE[$origin] ?? null) : null;
                $originText = $origin === null ? 'value' : $str($origin);
                if ($unit !== null) {
                    return "Too big: expected {$originText} to have {$adj}{$str($issue['maximum'] ?? null)} {$unit}";
                }

                return "Too big: expected {$originText} to be {$adj}{$str($issue['maximum'] ?? null)}";

            case 'too_small':
                $adj = ($issue['inclusive'] ?? false) ? '>=' : '>';
                $origin = $issue['origin'] ?? Undefined::Value;
                $unit = is_string($origin) ? (self::SIZABLE[$origin] ?? null) : null;
                if ($unit !== null) {
                    return "Too small: expected {$str($origin)} to have {$adj}{$str($issue['minimum'] ?? null)} {$unit}";
                }

                return "Too small: expected {$str($origin)} to be {$adj}{$str($issue['minimum'] ?? null)}";

            case 'invalid_format':
                $format = $issue['format'] ?? null;

                return match ($format) {
                    'starts_with' => "Invalid string: must start with \"{$str($issue['prefix'] ?? null)}\"",
                    'ends_with' => "Invalid string: must end with \"{$str($issue['suffix'] ?? null)}\"",
                    'includes' => "Invalid string: must include \"{$str($issue['includes'] ?? null)}\"",
                    'regex' => "Invalid string: must match pattern {$str($issue['pattern'] ?? null)}",
                    default => 'Invalid ' . (is_string($format) ? (self::FORMATS[$format] ?? $format) : $str($format)),
                };

            case 'not_multiple_of':
                return "Invalid number: must be a multiple of {$str($issue['divisor'] ?? null)}";

            case 'unrecognized_keys':
                /** @var list<string> $keys */
                $keys = $issue['keys'] ?? [];

                return 'Unrecognized key' . (count($keys) > 1 ? 's' : '') . ': ' . Util::joinValues($keys, ', ');

            case 'invalid_key':
                return "Invalid key in {$str($issue['origin'] ?? null)}";

            case 'invalid_union':
                $options = $issue['options'] ?? null;
                if (is_array($options) && $options !== []) {
                    return 'Invalid discriminator value. Expected ' . implode(' | ', array_map(
                        static fn (mixed $o): string => "'" . Util::jsString($o) . "'",
                        $options,
                    ));
                }

                return 'Invalid input';

            case 'invalid_element':
                return "Invalid value in {$str($issue['origin'] ?? null)}";

            default:
                return 'Invalid input';
        }
    }
}
