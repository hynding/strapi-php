<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/**
 * Mirrors engine/source.js: light-weight reading of source files without a full parser — comment
 * stripping, normalisation for template matching, and recognising the core-factory one-liners
 * (`createCoreController('api::x.x')`) that make up most of a project's `src/api`.
 *
 * @phpstan-type CoreFactory array{kind: 'controller'|'service'|'router', factory: string, uid: string, plain: bool}
 */
final class Source
{
    public const FACTORIES = ['createCoreController' => 'controller', 'createCoreService' => 'service', 'createCoreRouter' => 'router'];

    /**
     * Removes `//`, `#` (PHP) and `/* *\/` comments, leaving string literals alone.
     *
     * @param 'js'|'ts'|'php' $lang
     */
    public static function stripComments(string $code, string $lang): string
    {
        $out = '';
        $length = strlen($code);
        $i = 0;
        while ($i < $length) {
            $char = $code[$i];
            $next = $code[$i + 1] ?? '';

            if ($char === '"' || $char === "'" || $char === '`') {
                $end = $i + 1;
                while ($end < $length && $code[$end] !== $char) {
                    if ($code[$end] === '\\') {
                        $end++;
                    }
                    $end++;
                }
                $out .= substr($code, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($code, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }
            if (($char === '/' && $next === '/') || ($lang === 'php' && $char === '#')) {
                $end = strpos($code, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }
            $out .= $char;
            $i++;
        }

        return $out;
    }

    /**
     * Comments gone, whitespace collapsed, no spaces around punctuation: enough to recognise an unchanged template.
     *
     * @param 'js'|'ts'|'php' $lang
     */
    public static function normalize(string $code, string $lang): string
    {
        $code = self::stripComments($code, $lang);
        $code = (string) preg_replace('/\s+/', ' ', $code);
        $code = (string) preg_replace('/\s*([\[\]{}(),:;=>])\s*/', '$1', $code);

        return trim($code);
    }

    /**
     * The core factory a controller / service / router file calls, if it is one of the one-liners
     * `strapi generate` writes. `plain` is false when the file customises the factory (a second
     * argument, or any other code), in which case only a stub can be generated.
     *
     * @param 'js'|'ts'|'php' $lang
     * @return CoreFactory|null
     */
    public static function detectCoreFactory(string $code, string $lang): ?array
    {
        $stripped = self::stripComments($code, $lang);
        if (preg_match('/\b(createCore(?:Controller|Service|Router))\s*\(\s*([\'"])([^\'"]+)\2\s*(\)|,)/', $stripped, $m) !== 1) {
            return null;
        }
        [, $factory, , $uid, $closing] = $m;

        $plain = $closing === ')';
        if ($plain) {
            $rest = $lang === 'php' ? self::withoutPhpBoilerplate($stripped, $factory, $uid) : self::withoutJsBoilerplate($stripped, $factory, $uid);
            $plain = trim($rest) === '';
        }

        return ['kind' => self::FACTORIES[$factory], 'factory' => $factory, 'uid' => $uid, 'plain' => $plain];
    }

    private static function withoutJsBoilerplate(string $code, string $factory, string $uid): string
    {
        $q = preg_quote($uid, '/');
        $patterns = [
            '/[\'"]use strict[\'"];?/',
            '/const\s*\{\s*' . $factory . '\s*\}\s*=\s*require\(\s*[\'"]@strapi\/strapi[\'"]\s*\)\.factories;?/',
            '/const\s*\{\s*factories\s*\}\s*=\s*require\(\s*[\'"]@strapi\/strapi[\'"]\s*\);?/',
            '/import\s*\{\s*factories\s*\}\s*from\s*[\'"]@strapi\/strapi[\'"];?/',
            '/(module\.exports\s*=|export\s+default)\s*(factories\.)?' . $factory . '\(\s*[\'"]' . $q . '[\'"]\s*\);?/',
        ];

        return (string) preg_replace($patterns, '', $code);
    }

    private static function withoutPhpBoilerplate(string $code, string $factory, string $uid): string
    {
        $q = preg_quote($uid, '/');
        $patterns = [
            '/<\?php/',
            '/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/',
            '/use\s+Strapi\\\\Core\\\\Factories\s*;/',
            '/return\s+Factories::' . $factory . '\(\s*[\'"]' . $q . '[\'"]\s*\)\s*;/',
        ];

        return (string) preg_replace($patterns, '', $code);
    }

    /** The header comment of a `strapi generate` file: `article controller`. */
    public static function describe(string $kind, string $uid): string
    {
        $name = substr($uid, (int) strrpos($uid, '.') + 1);

        return "{$name} {$kind}";
    }
}
