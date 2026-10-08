<?php

declare(strict_types=1);

namespace Strapi\Openapi\Utils;

use Strapi\Openapi\Constants;

/**
 * Port of packages/core/openapi/src/utils/debug.ts (`createDebugger`, a `debug` package logger).
 *
 * Like `debug`, a logger prints to stderr only when its namespace is enabled by the `DEBUG`
 * environment variable (comma or space separated patterns, `*` wildcards, `-` exclusions);
 * `%o`, `%O`, `%s`, `%d` placeholders are replaced by the arguments.
 */
final class Debug
{
    /** @return \Closure(string, mixed...): void */
    public static function createDebugger(?string $section = null, string $namespace = Constants::DEBUG_NAMESPACE): \Closure
    {
        $name = $section !== null ? "{$namespace}:{$section}" : $namespace;

        return static function (string $format, mixed ...$args) use ($name): void {
            if (!self::enabled($name)) {
                return;
            }

            $message = preg_replace_callback('/%([oOsdj%])/', static function (array $m) use (&$args): string {
                if ($m[1] === '%') {
                    return '%';
                }
                if ($args === []) {
                    return $m[0];
                }
                $arg = array_shift($args);

                return is_string($arg) && $m[1] === 's' ? $arg : (string) json_encode($arg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }, $format);

            file_put_contents('php://stderr', "  {$name} {$message}\n");
        };
    }

    public static function enabled(string $name): bool
    {
        $patterns = getenv('DEBUG');
        if (!is_string($patterns) || $patterns === '') {
            return false;
        }

        $enabled = false;
        foreach (preg_split('/[\s,]+/', $patterns) ?: [] as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $negated = str_starts_with($pattern, '-');
            $regex = '/^' . str_replace('\*', '.*?', preg_quote($negated ? substr($pattern, 1) : $pattern, '/')) . '$/';
            if (preg_match($regex, $name) === 1) {
                if ($negated) {
                    return false;
                }
                $enabled = true;
            }
        }

        return $enabled;
    }
}
