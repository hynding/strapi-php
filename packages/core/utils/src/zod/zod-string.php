<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.string()`, and the string formats `z.email()` / `z.uuid()` (a string schema whose first
 * check is the format). Lengths count UTF-16 code units, as JavaScript does.
 */
class ZodString extends ZodType
{
    public const string EMAIL_PATTERN = "/^(?!\\.)(?!.*\\.\\.)([A-Za-z0-9_'+\\-\\.]*)[A-Za-z0-9_+-]@([A-Za-z0-9][A-Za-z0-9\\-]*\\.)+[A-Za-z]{2,}$/";
    public const string UUID_PATTERN = '/^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}|00000000-0000-0000-0000-000000000000|ffffffff-ffff-ffff-ffff-ffffffffffff)$/';
    public const string GUID_PATTERN = '/^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/';
    private const string DATE_SOURCE = '(?:(?:\d\d[2468][048]|\d\d[13579][26]|\d\d0[48]|[02468][048]00|[13579][26]00)-02-29|\d{4}-(?:(?:0[13578]|1[02])-(?:0[1-9]|[12]\d|3[01])|(?:0[469]|11)-(?:0[1-9]|[12]\d|30)|(?:02)-(?:0[1-9]|1\d|2[0-8])))';

    protected bool $coerce = false;

    /** @param string|array<string, mixed>|null $params */
    public function __construct(string|array|null $params = null, bool $coerce = false)
    {
        $this->withParams($params);
        $this->coerce = $coerce;
    }

    public function type(): string
    {
        return 'string';
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        if ($this->coerce) {
            $payload->value = Util::jsString($payload->value);
        }
        if (is_string($payload->value)) {
            return $payload;
        }

        return $this->issue($payload, ['expected' => 'string', 'code' => 'invalid_type']);
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['coerce' => $this->coerce];
    }

    /** The last format check's name (`email`, `uuid`, `datetime`, `regex`, ...), or null. */
    public function format(): ?string
    {
        $format = null;
        foreach ($this->checks as $check) {
            if ($check->kind() === 'string_format' && is_string($check->def['format'] ?? null)) {
                $format = $check->def['format'];
            }
        }

        return $format;
    }

    /** @param string|array<string, mixed>|null $params */
    public function min(int $minLength, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::minLength($minLength, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function max(int $maxLength, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::maxLength($maxLength, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function length(int $length, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::length($length, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function nonempty(string|array|null $params = null): static
    {
        return $this->check(ZodCheck::minLength(1, $params));
    }

    /**
     * @param string $pattern a PCRE pattern with delimiters, e.g. `'/^[a-z]+$/i'`
     * @param string|array<string, mixed>|null $params
     */
    public function regex(string $pattern, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::regex($pattern, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function startsWith(string $prefix, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::substring('starts_with', $prefix, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function endsWith(string $suffix, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::substring('ends_with', $suffix, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function includes(string $includes, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::substring('includes', $includes, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function email(string|array|null $params = null): static
    {
        $pattern = is_array($params) && is_string($params['pattern'] ?? null) ? $params['pattern'] : self::EMAIL_PATTERN;

        return $this->check(ZodCheck::stringFormat('email', $pattern, self::formatParams($params)));
    }

    /**
     * `uuid()`; `['version' => 'v4']` restricts the version.
     *
     * @param string|array<string, mixed>|null $params
     */
    public function uuid(string|array|null $params = null): static
    {
        $version = is_array($params) && is_string($params['version'] ?? null) ? $params['version'] : null;
        $pattern = self::UUID_PATTERN;
        if ($version !== null) {
            $n = (int) ltrim($version, 'v');
            if ($n < 1 || $n > 8) {
                throw new \InvalidArgumentException("Invalid UUID version: \"{$version}\"");
            }
            $pattern = "/^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-{$n}[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12})$/";
        }

        return $this->check(ZodCheck::stringFormat('uuid', $pattern, self::formatParams($params)));
    }

    /** @param string|array<string, mixed>|null $params */
    public function guid(string|array|null $params = null): static
    {
        return $this->check(ZodCheck::stringFormat('guid', self::GUID_PATTERN, self::formatParams($params)));
    }

    /**
     * ISO 8601 datetime (`z.string().datetime()` / `z.iso.datetime()`); params `offset`,
     * `local` and `precision` as in zod.
     *
     * @param string|array<string, mixed>|null $params
     */
    public function datetime(string|array|null $params = null): static
    {
        $options = is_array($params) ? $params : [];
        $precision = isset($options['precision']) && is_int($options['precision']) ? $options['precision'] : null;
        $alternatives = ['Z'];
        if (!empty($options['local'])) {
            $alternatives[] = '';
        }
        if (!empty($options['offset'])) {
            $alternatives[] = '([+-](?:[01]\d|2[0-3]):[0-5]\d)';
        }
        $time = self::timeSource($precision) . '(?:' . implode('|', $alternatives) . ')';
        $pattern = '/^' . self::DATE_SOURCE . 'T(?:' . $time . ')$/';

        return $this->check(ZodCheck::stringFormat('datetime', $pattern, self::formatParams($params)));
    }

    /**
     * ISO date `YYYY-MM-DD` (`z.string().date()` / `z.iso.date()`).
     *
     * @param string|array<string, mixed>|null $params
     */
    public function date(string|array|null $params = null): static
    {
        return $this->check(ZodCheck::stringFormat('date', '/^' . self::DATE_SOURCE . '$/', self::formatParams($params)));
    }

    /**
     * ISO time `HH:MM[:SS[.s+]]` (`z.string().time()` / `z.iso.time()`).
     *
     * @param string|array<string, mixed>|null $params
     */
    public function time(string|array|null $params = null): static
    {
        $precision = is_array($params) && isset($params['precision']) && is_int($params['precision']) ? $params['precision'] : null;

        return $this->check(ZodCheck::stringFormat('time', '/^' . self::timeSource($precision) . '$/', self::formatParams($params)));
    }

    /**
     * `url()`: an absolute URL (best effort; zod relies on the WHATWG URL parser).
     *
     * @param string|array<string, mixed>|null $params
     */
    public function url(string|array|null $params = null): static
    {
        $p = Util::normalizeParams(self::formatParams($params));

        return $this->check(new ZodCheck(static function (ParsePayload $payload, ZodCheck $check): void {
            $value = is_string($payload->value) ? trim($payload->value) : '';
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:\S+$/', $value) === 1 && parse_url($value) !== false) {
                return;
            }
            $payload->issues[] = [
                'code' => 'invalid_format',
                'format' => 'url',
                'input' => $payload->value,
                'inst' => $check,
                'continue' => !$check->abort,
            ];
        }, ['check' => 'string_format', 'format' => 'url'], $p['error'], (bool) $p['abort']));
    }

    public function trim(): static
    {
        return $this->check(ZodCheck::overwrite(static fn (mixed $v): mixed => is_string($v) ? trim($v, " \t\n\r\0\x0B\f\u{00A0}\u{FEFF}") : $v));
    }

    public function toLowerCase(): static
    {
        return $this->check(ZodCheck::overwrite(static fn (mixed $v): mixed => is_string($v) ? mb_strtolower($v) : $v));
    }

    public function toUpperCase(): static
    {
        return $this->check(ZodCheck::overwrite(static fn (mixed $v): mixed => is_string($v) ? mb_strtoupper($v) : $v));
    }

    private static function timeSource(?int $precision): string
    {
        $hhmm = '(?:[01]\d|2[0-3]):[0-5]\d';

        return match (true) {
            $precision === null => $hhmm . '(?::[0-5]\d(?:\.\d+)?)?',
            $precision === -1 => $hhmm,
            $precision === 0 => $hhmm . ':[0-5]\d',
            default => $hhmm . ':[0-5]\d\.\d{' . $precision . '}',
        };
    }

    /**
     * Drop format options (`version`, `offset`, `pattern`, ...) before normalizing error params.
     *
     * @param string|array<string, mixed>|null $params
     *
     * @return string|array<string, mixed>|null
     */
    private static function formatParams(string|array|null $params): string|array|null
    {
        if (!is_array($params)) {
            return $params;
        }

        return array_intersect_key($params, array_flip(['message', 'error', 'abort', 'when']));
    }
}
