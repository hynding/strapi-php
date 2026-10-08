<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

use Strapi\Utils\Primitives\Strings;

/** `yup.string()` */
class YupString extends Yup
{
    protected string $type = 'string';

    // yup 0.32.9 string.js regexes
    private const string R_EMAIL = '/^((([a-z]|\d|[!#\$%&\'\*\+\-\/=\?\^_`{\|}~]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])+(\.([a-z]|\d|[!#\$%&\'\*\+\-\/=\?\^_`{\|}~]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])+)*)|((\x22)((((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(([\x01-\x08\x0b\x0c\x0e-\x1f\x7f]|\x21|[\x23-\x5b]|[\x5d-\x7e]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(\\\\([\x01-\x09\x0b\x0c\x0d-\x7f]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}]))))*(((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(\x22)))@((([a-z]|\d|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(([a-z]|\d|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])*([a-z]|\d|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])))\.)+(([a-z]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(([a-z]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])*([a-z]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])))$/iu';

    private const string R_URL = '/^((https?|ftp):)?\/\/(((([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(%[\da-f]{2})|[!\$&\'\(\)\*\+,;=]|:)*@)?(((\d|[1-9]\d|1\d\d|2[0-4]\d|25[0-5])\.(\d|[1-9]\d|1\d\d|2[0-4]\d|25[0-5])\.(\d|[1-9]\d|1\d\d|2[0-4]\d|25[0-5])\.(\d|[1-9]\d|1\d\d|2[0-4]\d|25[0-5]))|((([a-z]|\d|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(([a-z]|\d|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])*([a-z]|\d|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])))\.)+(([a-z]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(([a-z]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])*([a-z]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])))\.?)(:\d*)?)(\/((([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(%[\da-f]{2})|[!\$&\'\(\)\*\+,;=]|:|@)+(\/(([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(%[\da-f]{2})|[!\$&\'\(\)\*\+,;=]|:|@)*)*)?)?(\?((([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(%[\da-f]{2})|[!\$&\'\(\)\*\+,;=]|:|@)|[\x{E000}-\x{F8FF}]|\/|\?)*)?(\#((([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(%[\da-f]{2})|[!\$&\'\(\)\*\+,;=]|:|@)|\/|\?)*)?$/iu';

    private const string R_UUID = '/^(?:[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|00000000-0000-0000-0000-000000000000)$/i';

    protected function typeCheck(mixed $value): bool
    {
        return is_string($value);
    }

    protected function typeTransform(mixed $value): mixed
    {
        if ($this->isType($value)) {
            return $value;
        }

        return match (true) {
            is_int($value), is_float($value) => Yup::jsNumberToString($value),
            is_bool($value) => $value ? 'true' : 'false',
            default => $value, // null, arrays, objects ("[object Object]") stay as they are
        };
    }

    public function isPresent(mixed $value): bool
    {
        return parent::isPresent($value) && (!is_string($value) || $value !== '');
    }

    /** JS `string.length` (UTF-16 code units). */
    public static function jsLength(string $value): int
    {
        return intdiv(strlen((string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    public function length(int|Reference $length, string|\Closure $message = Locale::STRING_LENGTH): static
    {
        return $this->test([
            'message' => $message,
            'name' => 'length',
            'exclusive' => true,
            'params' => ['length' => $length],
            'test' => static fn (mixed $value, TestContext $ctx): bool => Yup::isAbsent($value) || self::jsLength(Yup::jsString($value)) === $ctx->resolve($length),
        ]);
    }

    public function min(int|Reference $min, string|\Closure $message = Locale::STRING_MIN): static
    {
        return $this->test([
            'message' => $message,
            'name' => 'min',
            'exclusive' => true,
            'params' => ['min' => $min],
            'test' => static fn (mixed $value, TestContext $ctx): bool => Yup::isAbsent($value) || self::jsLength(Yup::jsString($value)) >= $ctx->resolve($min),
        ]);
    }

    public function max(int|Reference $max, string|\Closure $message = Locale::STRING_MAX): static
    {
        return $this->test([
            'message' => $message,
            'name' => 'max',
            'exclusive' => true,
            'params' => ['max' => $max],
            'test' => static fn (mixed $value, TestContext $ctx): bool => Yup::isAbsent($value) || self::jsLength(Yup::jsString($value)) <= $ctx->resolve($max),
        ]);
    }

    /**
     * `matches(regex, message)` or `matches(regex, { excludeEmptyString, message, name })`. The regex is
     * a delimited PCRE pattern (`/^[a-z]+$/i`) or a bare JS `RegExp` source (`^[a-z]+$`).
     *
     * @param string|\Closure|array{excludeEmptyString?: bool, message?: string|\Closure, name?: string}|null $options
     */
    public function matches(string $regex, string|\Closure|array|null $options = null): static
    {
        $excludeEmptyString = false;
        $message = null;
        $name = null;
        if (is_array($options)) {
            $excludeEmptyString = $options['excludeEmptyString'] ?? false;
            $message = $options['message'] ?? null;
            $name = $options['name'] ?? null;
        } elseif ($options !== null && $options !== '') {
            $message = $options;
        }
        $pattern = self::toPhpRegex($regex);

        return $this->test([
            'name' => $name ?? 'matches',
            'message' => $message ?? Locale::STRING_MATCHES,
            'params' => ['regex' => self::regexToString($regex)],
            'test' => static fn (mixed $value): bool => Yup::isAbsent($value)
                || ($value === '' && $excludeEmptyString)
                || preg_match($pattern, Yup::jsString($value)) === 1,
        ]);
    }

    /** `new RegExp(source)` → PHP delimited pattern; accepts `/.../flags` too. */
    public static function toPhpRegex(string $regex): string
    {
        if (preg_match('~^/(.*)/([a-z]*)$~s', $regex, $m) === 1) {
            $flags = str_replace(['g', 'y'], '', $m[2]);

            return '/' . $m[1] . '/' . $flags . (str_contains($flags, 'u') ? '' : 'u');
        }

        return '/' . str_replace('/', '\/', $regex) . '/u';
    }

    /** How a JS RegExp prints (`/source/flags`), for the `${regex}` message param. */
    public static function regexToString(string $regex): string
    {
        if (preg_match('~^/(.*)/([a-z]*)$~s', $regex) === 1) {
            return $regex;
        }

        return '/' . str_replace('/', '\/', $regex) . '/';
    }

    private function matchesBuiltin(string $pattern, string $name, string|\Closure $message, bool $excludeEmptyString, string $printed): static
    {
        return $this->test([
            'name' => $name,
            'message' => $message,
            'params' => ['regex' => $printed],
            'test' => static fn (mixed $value): bool => Yup::isAbsent($value)
                || ($value === '' && $excludeEmptyString)
                || preg_match($pattern, Yup::jsString($value)) === 1,
        ]);
    }

    public function email(string|\Closure $message = Locale::STRING_EMAIL): static
    {
        return $this->matchesBuiltin(self::R_EMAIL, 'email', $message, true, substr(self::R_EMAIL, 0, -1));
    }

    public function url(string|\Closure $message = Locale::STRING_URL): static
    {
        return $this->matchesBuiltin(self::R_URL, 'url', $message, true, substr(self::R_URL, 0, -1));
    }

    public function uuid(string|\Closure $message = Locale::STRING_UUID): static
    {
        return $this->matchesBuiltin(self::R_UUID, 'uuid', $message, false, self::R_UUID);
    }

    /** null → '' and a '' default. */
    public function ensure(): static
    {
        return $this->default('')->transform(static fn (mixed $val): mixed => $val === null ? '' : $val);
    }

    /** JS `String.prototype.trim` whitespace. */
    private static function jsTrim(string $value): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}\x{A0}]+|[\s\x{FEFF}\x{A0}]+$/u', '', $value);
    }

    public function trim(string|\Closure $message = Locale::STRING_TRIM): static
    {
        return $this
            ->transform(static fn (mixed $val): mixed => is_string($val) ? self::jsTrim($val) : $val)
            ->test([
                'message' => $message,
                'name' => 'trim',
                'test' => static fn (mixed $value): bool => Yup::isAbsent($value) || (is_string($value) && $value === self::jsTrim($value)),
            ]);
    }

    public function lowercase(string|\Closure $message = Locale::STRING_LOWERCASE): static
    {
        return $this
            ->transform(static fn (mixed $value): mixed => is_string($value) ? mb_strtolower($value) : $value)
            ->test([
                'message' => $message,
                'name' => 'string_case',
                'exclusive' => true,
                'test' => static fn (mixed $value): bool => Yup::isAbsent($value) || (is_string($value) && $value === mb_strtolower($value)),
            ]);
    }

    public function uppercase(string|\Closure $message = Locale::STRING_UPPERCASE): static
    {
        return $this
            ->transform(static fn (mixed $value): mixed => is_string($value) ? mb_strtoupper($value) : $value)
            ->test([
                'message' => $message,
                'name' => 'string_case',
                'exclusive' => true,
                'test' => static fn (mixed $value): bool => Yup::isAbsent($value) || (is_string($value) && $value === mb_strtoupper($value)),
            ]);
    }

    /** yup.ts: `.isCamelCase()` */
    public function isCamelCase(string|\Closure $message = '${path} is not in camel case (anExampleOfCamelCase)'): static
    {
        return $this->test('is in camelCase', $message, static fn (mixed $value): bool => Yup::truthy($value) ? Strings::isCamelCase(Yup::jsString($value)) : true);
    }

    /** yup.ts: `.isKebabCase()` */
    public function isKebabCase(string|\Closure $message = '${path} is not in kebab case (an-example-of-kebab-case)'): static
    {
        return $this->test('is in kebab-case', $message, static fn (mixed $value): bool => Yup::truthy($value) ? Strings::isKebabCase(Yup::jsString($value)) : true);
    }
}
