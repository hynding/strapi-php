<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/**
 * Mirrors engine/ir.js: the intermediate form of a config file, the contract between the
 * evaluators (one per language) and the emitters (one per language). A document is
 *
 *   { source, form: 'function' | 'value', value, warnings: [] }
 *
 * where `value` is JSON (objects, lists, scalars) whose leaves may be
 *
 *   { "$env": "string"|"int"|"float"|"bool"|"json"|"array"|"date"|"oneOf", "key": "PORT", "default"?: any, "expected"?: [] }
 *   { "$concat": [ part, ... ] }                 string concatenation of literals and leaves
 *   { "$root": true }                            the project root directory
 *   { "$todo": "<source>", "lang": "js"|"ts"|"php" }   an expression that could not be translated
 *
 * JSON objects are `stdClass` here (so `{}` and `[]` stay distinct), lists are PHP lists.
 */
final class Ir
{
    public const ENV_TYPES = ['string', 'int', 'float', 'bool', 'json', 'array', 'date', 'oneOf'];

    /** @param list<string>|null $expected */
    public static function env(string $type, string $key, mixed $default = null, bool $hasDefault = false, ?array $expected = null): \stdClass
    {
        $ref = new \stdClass();
        $ref->{'$env'} = $type;
        $ref->key = $key;
        if ($hasDefault) {
            $ref->default = $default;
        }
        if ($expected !== null) {
            $ref->expected = $expected;
        }

        return $ref;
    }

    /** @param list<mixed> $parts */
    public static function concat(array $parts): \stdClass
    {
        // merge adjacent string literals
        $merged = [];
        foreach ($parts as $part) {
            if (is_string($part) && $merged !== [] && is_string($merged[array_key_last($merged)])) {
                $merged[array_key_last($merged)] .= $part;
            } else {
                $merged[] = $part;
            }
        }
        $node = new \stdClass();
        $node->{'$concat'} = $merged;

        return $node;
    }

    public static function root(): \stdClass
    {
        $node = new \stdClass();
        $node->{'$root'} = true;

        return $node;
    }

    /** @param 'js'|'ts'|'php' $lang */
    public static function todo(string $source, string $lang): \stdClass
    {
        $node = new \stdClass();
        $node->{'$todo'} = $source;
        $node->lang = $lang;

        return $node;
    }

    public static function isEnv(mixed $value): bool
    {
        return $value instanceof \stdClass && isset($value->{'$env'});
    }

    public static function isConcat(mixed $value): bool
    {
        return $value instanceof \stdClass && isset($value->{'$concat'});
    }

    public static function isRoot(mixed $value): bool
    {
        return $value instanceof \stdClass && isset($value->{'$root'});
    }

    public static function isTodo(mixed $value): bool
    {
        return $value instanceof \stdClass && isset($value->{'$todo'});
    }

    public static function isLeaf(mixed $value): bool
    {
        return self::isEnv($value) || self::isConcat($value) || self::isRoot($value) || self::isTodo($value);
    }

    /** Whether any leaf reads the environment (decides the `({ env }) =>` / `fn (EnvHelper $env)` form). */
    public static function usesEnv(mixed $value): bool
    {
        return self::any($value, static fn (mixed $v): bool => self::isEnv($v));
    }

    public static function usesRoot(mixed $value): bool
    {
        return self::any($value, static fn (mixed $v): bool => self::isRoot($v));
    }

    public static function countTodos(mixed $value): int
    {
        $count = 0;
        self::any($value, static function (mixed $v) use (&$count): bool {
            if (self::isTodo($v)) {
                $count++;
            }

            return false;
        });

        return $count;
    }

    /** @param callable(mixed): bool $predicate */
    private static function any(mixed $value, callable $predicate): bool
    {
        if ($predicate($value)) {
            return true;
        }
        if (self::isConcat($value)) {
            foreach ($value->{'$concat'} as $part) {
                if (self::any($part, $predicate)) {
                    return true;
                }
            }
        }
        if (self::isEnv($value) && isset($value->default)) {
            return self::any($value->default, $predicate);
        }
        if ($value instanceof \stdClass && !self::isLeaf($value)) {
            foreach (get_object_vars($value) as $child) {
                if (self::any($child, $predicate)) {
                    return true;
                }
            }
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if (self::any($child, $predicate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A document, decoded from the JSON the other runtime's evaluator printed.
     *
     * @return array{source: string, form: string, value: mixed, warnings: list<string>}
     */
    public static function decode(string $json): array
    {
        $doc = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$doc instanceof \stdClass || !isset($doc->form)) {
            throw new \RuntimeException('Not a config IR document: ' . substr($json, 0, 200));
        }

        return [
            'source' => (string) ($doc->source ?? ''),
            'form' => (string) $doc->form,
            'value' => $doc->value ?? null,
            'warnings' => array_values(array_map(strval(...), (array) ($doc->warnings ?? []))),
        ];
    }

    /** @param array{source: string, form: string, value: mixed, warnings: list<string>} $doc */
    public static function encode(array $doc): string
    {
        return json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
