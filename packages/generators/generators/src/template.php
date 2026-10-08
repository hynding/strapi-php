<?php

declare(strict_types=1);

namespace Strapi\Generators;

/**
 * Not an upstream file: the handlebars subset the generator templates use, without the
 * dependency. `{{ name }}` inserts a value (no HTML escaping: the templates produce code, not
 * HTML), `{{ helper name }}` inserts `helper(value)`. Dotted names (`{{ a.b }}`) read nested
 * arrays. A missing value renders as an empty string, like handlebars.
 */
final class Template
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, callable(mixed): mixed> $helpers
     */
    public static function render(string $template, array $data, array $helpers = []): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z_][\w-]*)(?:\s+([A-Za-z_][\w.-]*))?\s*\}\}/',
            static function (array $m) use ($data, $helpers): string {
                if (isset($m[2])) {
                    $helper = $helpers[$m[1]] ?? throw new \RuntimeException("Missing helper: \"{$m[1]}\"");

                    return self::toString($helper(self::lookup($data, $m[2])));
                }

                return self::toString(self::lookup($data, $m[1]));
            },
            $template,
        );
    }

    /** @param array<string, mixed> $data */
    private static function lookup(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function toString(mixed $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            $value === true => 'true',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
