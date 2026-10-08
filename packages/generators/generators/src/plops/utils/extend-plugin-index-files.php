<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Utils;

/**
 * Port of src/plops/utils/extend-plugin-index-files.ts (`appendToFile`): registers a generated
 * file in a plugin's index file.
 *
 * Upstream edits the JS AST of `export default {...}` / `module.exports = {...}` with jscodeshift
 * and adds an import. PHP plugin index files return arrays and `require` their entries, so this
 * port edits the array the file returns (found with PHP's tokenizer) and adds:
 *
 * - `index`: `'<name>' => require __DIR__ . '/<name>.php',`
 * - `content-type`: `'<name>' => ['schema' => json_decode(... __DIR__ . '/<name>/schema.json' ...)],`
 * - `routes`: `...(require __DIR__ . '/<name>.php')['routes'],` in the returned router's `routes`
 *   (with `'router' => 'core'`: `...(require __DIR__ . '/<name>.php')->routes($strapi),` for a
 *   `createCoreRouter()` file).
 *
 * An entry already present is not added twice. An empty file, or one without a returned array,
 * becomes a new index file holding the entry (upstream's fallback replaces the export).
 *
 * @phpstan-type AppendConfig array{type: 'content-type'|'index'|'routes', singularName: string, router?: 'core'|'custom'}
 */
final class ExtendPluginIndexFiles
{
    /** @param AppendConfig $config */
    public static function appendToFile(string $template, array $config): string
    {
        $singularName = $config['singularName'];
        $type = $config['type'];
        if ($singularName === '') {
            throw new \InvalidArgumentException('Invalid config: singularName and type are required');
        }

        $source = trim($template) === '' ? '' : $template;
        $returned = $source !== '' ? self::findReturnedArray($source) : null;

        if ($returned === null) {
            return self::newFile($config);
        }

        if ($type === 'routes') {
            $routes = self::findKeyArray($source, $returned, 'routes');
            if ($routes === null) {
                return self::newFile($config);
            }
            if (str_contains(substr($source, $routes['open'], $routes['close'] - $routes['open']), "/{$singularName}.php'")) {
                return $source;
            }

            return self::insert($source, $routes, self::entry($config));
        }

        if (self::hasKey($source, $returned, $singularName)) {
            return $source;
        }

        return self::insert($source, $returned, self::entry($config));
    }

    /** @param AppendConfig $config */
    private static function entry(array $config): string
    {
        $name = $config['singularName'];
        $key = var_export($name, true);

        return match ($config['type']) {
            'content-type' => "{$key} => [\n        'schema' => json_decode((string) file_get_contents(__DIR__ . '/{$name}/schema.json'), true, flags: JSON_THROW_ON_ERROR),\n    ]",
            'index' => "{$key} => require __DIR__ . '/{$name}.php'",
            'routes' => ($config['router'] ?? 'custom') === 'core'
                ? "...(require __DIR__ . '/{$name}.php')->routes(\$strapi)"
                : "...(require __DIR__ . '/{$name}.php')['routes']",
        };
    }

    /** @param AppendConfig $config */
    private static function newFile(array $config): string
    {
        $entry = self::entry($config);

        if ($config['type'] === 'routes') {
            return <<<PHP
                <?php

                declare(strict_types=1);

                use Strapi\\Core\\Strapi;

                return static fn (Strapi \$strapi): array => [
                    'type' => 'content-api',
                    'routes' => [
                        {$entry},
                    ],
                ];

                PHP;
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            return [
                {$entry},
            ];

            PHP;
    }

    /**
     * The token list with each token's byte offset.
     *
     * @return list<array{0: int|string, 1: string, 2: int}> [id or char, text, offset]
     */
    private static function tokens(string $source): array
    {
        $tokens = [];
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [$token, $token];
            $tokens[] = [$id, $text, $offset];
            $offset += strlen($text);
        }

        return $tokens;
    }

    private static function isTrivia(int|string $id): bool
    {
        return in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * The array literal of the top-level `return` (`return [...]` or `return static fn (...): array => [...]`).
     *
     * @return array{open: int, close: int}|null byte offsets of `[` and `]`
     */
    private static function findReturnedArray(string $source): ?array
    {
        $tokens = self::tokens($source);
        $braces = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $id = $tokens[$i][0];
            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $braces++;
            } elseif ($id === '}') {
                $braces--;
            } elseif ($id === T_RETURN && $braces === 0) {
                $parens = 0;
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j][0];
                    if ($t === '(') {
                        $parens++;
                    } elseif ($t === ')') {
                        $parens--;
                    } elseif ($t === ';') {
                        return null;
                    } elseif ($t === '[' && $parens === 0) {
                        $close = self::matchingBracket($tokens, $j);

                        return $close === null ? null : ['open' => $tokens[$j][2], 'close' => $tokens[$close][2]];
                    }
                }

                return null;
            }
        }

        return null;
    }

    /** @param list<array{0: int|string, 1: string, 2: int}> $tokens */
    private static function matchingBracket(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i][0] === '[') {
                $depth++;
            } elseif ($tokens[$i][0] === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The direct children of an array literal: `key => value` pairs whose key is a string literal.
     *
     * @param array{open: int, close: int} $array
     * @return list<array{key: string, valueIndex: int}>
     */
    private static function keys(string $source, array $array): array
    {
        $tokens = self::tokens($source);
        $out = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text, $offset] = $tokens[$i];
            if ($offset <= $array['open']) {
                continue;
            }
            if ($offset >= $array['close']) {
                break;
            }
            if (in_array($id, ['[', '(', '{', T_CURLY_OPEN], true)) {
                $depth++;
                continue;
            }
            if (in_array($id, [']', ')', '}'], true)) {
                $depth--;
                continue;
            }
            if ($depth !== 0 || $id !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $next = $i + 1;
            while ($next < $count && self::isTrivia($tokens[$next][0])) {
                $next++;
            }
            if ($next < $count && $tokens[$next][0] === T_DOUBLE_ARROW) {
                $value = $next + 1;
                while ($value < $count && self::isTrivia($tokens[$value][0])) {
                    $value++;
                }
                $out[] = ['key' => self::unquote($text), 'valueIndex' => $value];
            }
        }

        return $out;
    }

    private static function unquote(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
    }

    /** @param array{open: int, close: int} $array */
    private static function hasKey(string $source, array $array, string $key): bool
    {
        foreach (self::keys($source, $array) as $entry) {
            if ($entry['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * The array literal value of `'<key>' => [...]` in an array.
     *
     * @param array{open: int, close: int} $array
     * @return array{open: int, close: int}|null
     */
    private static function findKeyArray(string $source, array $array, string $key): ?array
    {
        $tokens = self::tokens($source);
        foreach (self::keys($source, $array) as $entry) {
            if ($entry['key'] !== $key || ($tokens[$entry['valueIndex']][0] ?? null) !== '[') {
                continue;
            }
            $close = self::matchingBracket($tokens, $entry['valueIndex']);

            return $close === null ? null : ['open' => $tokens[$entry['valueIndex']][2], 'close' => $tokens[$close][2]];
        }

        return null;
    }

    /**
     * Appends `$entry` as the last element of the array, on its own line.
     *
     * @param array{open: int, close: int} $array
     */
    private static function insert(string $source, array $array, string $entry): string
    {
        $openIndent = self::lineIndent($source, $array['open']);
        $closeLineStart = strrpos(substr($source, 0, $array['close']), "\n");
        $closeLineStart = $closeLineStart === false ? 0 : $closeLineStart + 1;
        $closeOnOwnLine = trim(substr($source, $closeLineStart, $array['close'] - $closeLineStart)) === '';
        $closeIndent = $closeOnOwnLine ? self::lineIndent($source, $array['close']) : $openIndent;
        $indent = $closeIndent . '    ';

        // the last element needs a trailing comma
        $lastSignificant = null;
        foreach (self::tokens($source) as [$id, $text, $offset]) {
            if ($offset > $array['open'] && $offset < $array['close'] && !self::isTrivia($id)) {
                $lastSignificant = [$id, $offset + strlen($text)];
            }
        }
        $head = substr($source, 0, $array['close']);
        if ($lastSignificant !== null && $lastSignificant[0] !== ',') {
            $head = substr($source, 0, $lastSignificant[1]) . ',' . substr($source, $lastSignificant[1], $array['close'] - $lastSignificant[1]);
        }

        $entry = str_replace("\n", "\n{$closeIndent}", $entry);

        return rtrim($head, " \t\n\r") . "\n{$indent}{$entry},\n{$closeIndent}" . substr($source, $array['close']);
    }

    private static function lineIndent(string $source, int $offset): string
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        preg_match('/^[ \t]*/', substr($source, $lineStart), $m);

        return $m[0] ?? '';
    }
}
