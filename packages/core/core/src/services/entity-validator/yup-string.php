<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/** `yup.string()` */
class YupString extends Yup
{
    protected string $type = 'string';

    protected function cast(mixed $value): mixed
    {
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $value;
    }

    protected function typeCheck(mixed $value): bool
    {
        return is_string($value);
    }

    public function min(int $min, string $message = '${path} must be at least ${min} characters'): static
    {
        return $this->test('min', Yup::interpolate($message, ['min' => $min, 'path' => '${path}']), static fn (mixed $v): bool => $v === null || $v instanceof Undefined || !is_string($v) || mb_strlen($v) >= $min);
    }

    public function max(int $max, string $message = '${path} must be at most ${max} characters'): static
    {
        return $this->test('max', Yup::interpolate($message, ['max' => $max, 'path' => '${path}']), static fn (mixed $v): bool => $v === null || $v instanceof Undefined || !is_string($v) || mb_strlen($v) <= $max);
    }

    /** @param array{excludeEmptyString?: bool, message?: string} $options */
    public function matches(string $regex, array $options = []): static
    {
        $excludeEmptyString = $options['excludeEmptyString'] ?? false;
        $message = $options['message'] ?? '${path} must match the following: "${regex}"';
        $pattern = self::toPhpRegex($regex);

        return $this->test('matches', Yup::interpolate($message, ['regex' => $regex, 'path' => '${path}']), static function (mixed $v) use ($pattern, $excludeEmptyString): bool {
            if ($v === null || $v instanceof Undefined || !is_string($v)) {
                return true;
            }
            if ($v === '' && $excludeEmptyString) {
                return true;
            }

            return preg_match($pattern, $v) === 1;
        });
    }

    /** `new RegExp(source)` → PHP delimited pattern; accepts `/.../flags` too. */
    public static function toPhpRegex(string $regex): string
    {
        if (preg_match('~^/(.*)/([a-z]*)$~s', $regex, $m) === 1) {
            return '/' . str_replace('/', '\/', $m[1]) . '/' . str_replace(['g', 'y'], '', $m[2]) . 'u';
        }

        return '/' . str_replace('/', '\/', $regex) . '/u';
    }

    public function email(string $message = '${path} must be a valid email'): static
    {
        // yup's email regex
        $rEmail = '/^((([a-z]|\d|[!#\$%&\'\*\+\-\/=\?\^_`{\|}~]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])+(\.([a-z]|\d|[!#\$%&\'\*\+\-\/=\?\^_`{\|}~]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])+)*)|((\x22)((((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(([\x01-\x08\x0b\x0c\x0e-\x1f\x7f]|\x21|[\x23-\x5b]|[\x5d-\x7e]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(\\\\([\x01-\x09\x0b\x0c\x0d-\x7f]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}]))))*(((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(\x22)))@((([a-z]|\d|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(([a-z]|\d|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])*([a-z]|\d|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])))\.)+(([a-z]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])|(([a-z]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])([a-z]|\d|-|\.|_|~|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])*([a-z]|[\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}])))$/iu';

        return $this->test('email', $message, static fn (mixed $v): bool => $v === null || $v instanceof Undefined || !is_string($v) || $v === '' || preg_match($rEmail, $v) === 1);
    }
}
