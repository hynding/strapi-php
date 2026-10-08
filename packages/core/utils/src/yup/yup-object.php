<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

use Strapi\Utils\EmptyObject;

/**
 * `yup.object(shape?)`. A JS object is an associative array here, or a JSON `{}` decoded to an
 * {@see EmptyObject}; `[]` counts as `{}` too, unless {@see self::rejectEmptyList()} (for values
 * decoded with EmptyObject markers, where `[]` was a JSON array). A field whose schema is null is a
 * known key without validation (yup's `undefined` shape entries).
 */
class YupObject extends Yup
{
    protected string $type = 'object';

    /** @var array<string, Yup|null> */
    public array $fields = [];

    /** @var list<string> field keys in yup's `_nodes` order (dependency-sorted) */
    protected array $nodes = [];

    /** @var list<string> */
    protected array $excludedEdges = [];

    /** @var list<string> keys used to sort nested errors (`_sortErrors`) */
    protected array $sortKeys = [];

    /** @param array<string, Yup|null> $shape */
    public function __construct(array $shape = [])
    {
        parent::__construct();
        if ($shape !== []) {
            $this->applyShape($shape);
        }
    }

    /** yup's `isObject`: a plain object (an associative array, an {@see EmptyObject}, or `[]`). */
    public static function isPlainObject(mixed $value): bool
    {
        return $value instanceof EmptyObject || (is_array($value) && ($value === [] || !array_is_list($value)));
    }

    protected function typeCheck(mixed $value): bool
    {
        if ($value === [] && ($this->spec['rejectEmptyList'] ?? false)) {
            return false;
        }

        return self::isPlainObject($value) || is_object($value);
    }

    /**
     * PHP port: `[]` fails the type check (it is a JSON array), as it does in JS. For data decoded
     * with {@see EmptyObject} markers (`$ctx->requestBody(true)`), where `{}` is not `[]`.
     */
    public function rejectEmptyList(bool $reject = true): static
    {
        $next = clone $this;
        $next->spec['rejectEmptyList'] = $reject;

        return $next;
    }

    protected function typeTransform(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = self::jsonParse($value, 'object');
        }

        return $this->isType($value) ? $value : null;
    }

    /**
     * `JSON.parse` for the object/array coercions: JSON objects become associative arrays, JSON
     * arrays lists. A JSON value of the other kind than `$want` (and invalid JSON, which yup
     * catches) gives null, as the coercion would.
     *
     * @param 'object'|'array' $want
     */
    public static function jsonParse(string $json, string $want): mixed
    {
        if (!json_validate($json)) {
            return null;
        }
        $decoded = json_decode($json, false);
        if ($want === 'object' ? !($decoded instanceof \stdClass) : !is_array($decoded)) {
            return null;
        }

        return self::toAssoc($decoded);
    }

    private static function toAssoc(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map(self::toAssoc(...), $value);
        }

        return $value;
    }

    protected function computeDefault(): mixed
    {
        if ($this->hasDefault()) {
            return parent::computeDefault();
        }
        if ($this->nodes === []) {
            return Undefined::value();
        }

        return $this->getDefaultFromShape();
    }

    /** @return array<string, mixed> */
    public function getDefaultFromShape(): array
    {
        $default = [];
        foreach ($this->nodes as $key) {
            $field = $this->fields[$key] ?? null;
            if ($field === null || $field instanceof YupLazy) {
                continue; // `undefined`: the key is left out
            }
            $value = $field->getDefault();
            if (!($value instanceof Undefined)) {
                $default[$key] = $value;
            }
        }

        return $default;
    }

    /** @param array<string, mixed> $options */
    protected function castValue(mixed $rawValue, array $options): mixed
    {
        $value = parent::castValue($rawValue, $options);
        if ($value instanceof Undefined) {
            return $this->getDefault();
        }
        // a JSON `{}`: cast as `[]`, and stays the marker unless the cast adds keys (defaults)
        $emptyObject = $value instanceof EmptyObject ? $value : null;
        if ($emptyObject !== null) {
            $value = [];
        } elseif (!$this->typeCheck($value) || !is_array($value)) {
            return $value;
        }

        $strip = (bool) ($options['stripUnknown'] ?? $this->spec['noUnknown'] ?? false);
        $props = [...$this->nodes, ...array_values(array_filter(array_map('strval', array_keys($value)), fn (string $k): bool => !in_array($k, $this->nodes, true)))];
        $intermediate = [];
        $isChanged = false;
        $validating = (bool) ($options['__validating'] ?? false);

        foreach ($props as $prop) {
            $field = $this->fields[$prop] ?? null;
            $exists = array_key_exists($prop, $value);
            if ($field !== null) {
                $inputValue = $exists ? $value[$prop] : Undefined::value();
                $innerOptions = [
                    ...$options,
                    'parent' => $intermediate,
                    '__validating' => $validating,
                    'path' => (isset($options['path']) && $options['path'] !== '' ? $options['path'] . '.' : '') . $prop,
                ];
                $resolved = $field->resolve([
                    'value' => $inputValue,
                    'context' => $options['context'] ?? Undefined::value(),
                    'parent' => $intermediate,
                ]);
                $fieldSpec = $resolved->spec();
                if ($fieldSpec['strip']) {
                    $isChanged = $isChanged || $exists;
                    continue;
                }
                $fieldValue = !$validating || !$fieldSpec['strict'] ? $field->cast($inputValue, $innerOptions) : $inputValue;
                if (!($fieldValue instanceof Undefined)) {
                    $intermediate[$prop] = $fieldValue;
                }
            } elseif ($exists && !$strip) {
                $intermediate[$prop] = $value[$prop];
            }

            $before = $exists ? $value[$prop] : Undefined::value();
            $after = array_key_exists($prop, $intermediate) ? $intermediate[$prop] : Undefined::value();
            if (!Yup::sameValue($after, $before) || (is_float($after) && is_nan($after))) {
                $isChanged = true;
            }
        }

        if (!$isChanged) {
            return $emptyObject ?? $value;
        }

        // Fields are cast in yup's node order (reverse declaration order without `when()`
        // dependencies); unlike a JS object literal the result keeps the input's key order and
        // only appends the keys the cast added (defaults).
        $ordered = [];
        foreach ($value as $key => $_) {
            if (array_key_exists($key, $intermediate)) {
                $ordered[$key] = $intermediate[$key];
            }
        }

        return $ordered + $intermediate;
    }

    protected function runValidation(mixed $value, array $options): array
    {
        $errors = [];
        $originalValue = array_key_exists('originalValue', $options) && !($options['originalValue'] instanceof Undefined) ? $options['originalValue'] : $value;
        $abortEarly = (bool) ($options['abortEarly'] ?? $this->spec['abortEarly']);
        $recursive = (bool) ($options['recursive'] ?? $this->spec['recursive']);
        $from = [['schema' => $this, 'value' => $originalValue], ...(is_array($options['from'] ?? null) ? $options['from'] : [])];

        $options['__validating'] = true;
        $options['originalValue'] = $originalValue;
        $options['from'] = $from;

        [$err, $value] = parent::runValidation($value, $options);
        if ($err !== null) {
            if ($abortEarly) {
                return [$err, $value];
            }
            $errors[] = $err;
        }

        if (!$recursive || !self::isPlainObject($value) || !$this->typeCheck($value)) {
            return [$errors[0] ?? null, $value];
        }
        $result = $value;
        if ($value instanceof EmptyObject) {
            $value = [];
        }
        /** @var array<string, mixed> $value */
        $originalValue = Yup::truthy($originalValue) ? $originalValue : $value;
        $parentPath = isset($options['path']) && is_string($options['path']) ? $options['path'] : null;

        $tests = [];
        foreach ($this->nodes as $key) {
            $field = $this->fields[$key] ?? null;
            if ($field === null) {
                continue;
            }
            $path = !str_contains($key, '.')
                ? ($parentPath !== null && $parentPath !== '' ? "{$parentPath}." : '') . $key
                : ($parentPath ?? '') . "[\"{$key}\"]";
            $fieldOptions = [
                ...$options,
                'path' => $path,
                'from' => $from,
                'strict' => true,
                'parent' => $value,
                'originalValue' => is_array($originalValue) && array_key_exists($key, $originalValue) ? $originalValue[$key] : Undefined::value(),
            ];
            $fieldValue = array_key_exists($key, $value) ? $value[$key] : Undefined::value();
            $tests[] = static fn (): ?YupError => $field->validateNested($fieldValue, $fieldOptions)[0];
        }

        $sortKeys = $this->sortKeys;
        $sort = static fn (YupError $a, YupError $b): int => self::keyIndex($sortKeys, $a) <=> self::keyIndex($sortKeys, $b);

        return [self::runTests($tests, $value, $parentPath, $abortEarly, $errors, $sort), $result];
    }

    /**
     * yup's `sortByKeyOrder` `findIndex`: the first key the error path *contains* (a substring
     * search, as yup does), Infinity when none; a path-less error sorts with the first key.
     *
     * @param list<string> $keys
     */
    private static function keyIndex(array $keys, YupError $err): float
    {
        foreach ($keys as $i => $key) {
            if ($err->path === null || str_contains($err->path, $key)) {
                return (float) $i;
            }
        }

        return INF;
    }

    /**
     * @param array<string, Yup|null> $additions
     * @param list<array{0: string, 1: string}>|array{0: string, 1: string} $excludes
     */
    public function shape(array $additions, array $excludes = []): static
    {
        $next = clone $this;
        $next->applyShape($additions, $excludes);

        return $next;
    }

    /**
     * @param array<string, Yup|null> $additions
     * @param list<array{0: string, 1: string}>|array{0: string, 1: string} $excludes
     */
    protected function applyShape(array $additions, array $excludes = []): void
    {
        foreach ($additions as $key => $schema) {
            $this->fields[(string) $key] = $schema;
        }
        $this->sortKeys = array_map('strval', array_keys($this->fields));
        if ($excludes !== []) {
            /** @var list<array{0: string, 1: string}> $pairs */
            $pairs = is_array($excludes[0]) ? $excludes : [$excludes];
            foreach ($pairs as [$first, $second]) {
                $this->excludedEdges[] = "{$first}-{$second}";
            }
        }
        $this->nodes = self::sortFields($this->fields, $this->excludedEdges);
    }

    /**
     * yup's `sortFields`: toposort of the fields by their `when()` sibling dependencies, reversed.
     *
     * @param array<string, Yup|null> $fields
     * @param list<string> $excludes
     * @return list<string>
     */
    private static function sortFields(array $fields, array $excludes): array
    {
        $edges = [];
        $nodes = [];
        $addNode = static function (string $depPath, string $key) use (&$edges, &$nodes, $excludes): void {
            $node = explode('.', (string) preg_replace('/\[.*$/', '', $depPath))[0];
            if (!in_array($node, $nodes, true)) {
                $nodes[] = $node;
            }
            if (!in_array("{$key}-{$node}", $excludes, true)) {
                $edges[] = [$key, $node];
            }
        };
        foreach ($fields as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, $nodes, true)) {
                $nodes[] = $key;
            }
            if ($value !== null) {
                foreach ($value->deps as $path) {
                    $addNode($path, $key);
                }
            }
        }

        return array_reverse(self::toposort($nodes, $edges));
    }

    /**
     * The `toposort` package's `toposort.array(nodes, edges)`.
     *
     * @param list<string> $nodes
     * @param list<array{0: string, 1: string}> $edges
     * @return list<string>
     */
    private static function toposort(array $nodes, array $edges): array
    {
        $cursor = count($nodes);
        $sorted = array_fill(0, $cursor, '');
        $visited = [];
        $outgoing = [];
        foreach ($edges as [$from, $to]) {
            $outgoing[$from] ??= [];
            if (!in_array($to, $outgoing[$from], true)) {
                $outgoing[$from][] = $to;
            }
        }
        $index = array_flip($nodes);

        $visit = static function (string $node, int $i, array $predecessors) use (&$visit, &$visited, &$sorted, &$cursor, $outgoing, $index): void {
            if (in_array($node, $predecessors, true)) {
                throw new \RuntimeException('Cyclic dependency, node was:' . json_encode($node));
            }
            if (isset($visited[$i])) {
                return;
            }
            $visited[$i] = true;
            $children = $outgoing[$node] ?? [];
            for ($j = count($children) - 1; $j >= 0; --$j) {
                $child = $children[$j];
                $visit($child, $index[$child], [...$predecessors, $node]);
            }
            $sorted[--$cursor] = $node;
        };

        for ($i = count($nodes) - 1; $i >= 0; --$i) {
            if (!isset($visited[$i])) {
                $visit($nodes[$i], $i, []);
            }
        }

        return array_values($sorted);
    }

    public function concat(?Yup $schema): Yup
    {
        $next = parent::concat($schema);
        if (!$next instanceof self) {
            return $next;
        }
        $nextFields = $next->fields;
        foreach ($this->fields as $field => $schemaOrNull) {
            $target = $nextFields[$field] ?? null;
            if (!array_key_exists($field, $nextFields)) {
                $nextFields[$field] = $schemaOrNull;
            } elseif ($target !== null && $schemaOrNull !== null) {
                $nextFields[$field] = $schemaOrNull->concat($target);
            }
        }
        $next->applyShape($nextFields);

        return $next;
    }

    /** @param list<string> $keys */
    public function pick(array $keys): static
    {
        $next = clone $this;
        $next->fields = [];
        $picked = [];
        foreach ($keys as $key) {
            if (isset($this->fields[$key])) {
                $picked[$key] = $this->fields[$key];
            }
        }
        $next->applyShape($picked);

        return $next;
    }

    /** @param list<string> $keys */
    public function omit(array $keys): static
    {
        $next = clone $this;
        $fields = $next->fields;
        foreach ($keys as $key) {
            unset($fields[$key]);
        }
        $next->fields = [];
        $next->applyShape($fields);

        return $next;
    }

    /** `noUnknown(noAllow = true, message)` or `noUnknown(message)`. */
    public function noUnknown(bool|string|\Closure $noAllow = true, string|\Closure $message = Locale::OBJECT_NO_UNKNOWN): static
    {
        if (!is_bool($noAllow)) {
            $message = $noAllow;
            $noAllow = true;
        }
        $next = $this->test([
            'name' => 'noUnknown',
            'exclusive' => true,
            'message' => $message,
            'test' => static function (mixed $value, TestContext $ctx) use ($noAllow): bool|YupError {
                if (Yup::isAbsent($value) || !is_array($value)) {
                    return true;
                }
                $schema = $ctx->schema;
                $known = $schema instanceof self ? array_map('strval', array_keys($schema->fields)) : [];
                $unknownKeys = array_values(array_filter(array_map('strval', array_keys($value)), static fn (string $k): bool => !in_array($k, $known, true)));

                return !$noAllow || $unknownKeys === [] ? true : $ctx->createError(['params' => ['unknown' => implode(', ', $unknownKeys)]]);
            },
        ]);
        $next->spec['noUnknown'] = $noAllow;

        return $next;
    }

    public function unknown(bool $allow = true, string|\Closure $message = Locale::OBJECT_NO_UNKNOWN): static
    {
        return $this->noUnknown(!$allow, $message);
    }

    /** yup.ts: `.onlyContainsFunctions()` */
    public function onlyContainsFunctions(string|\Closure $message = '${path} contains values that are not functions'): static
    {
        return $this->test('only contains functions', $message, static function (mixed $value): bool {
            if ($value instanceof Undefined) {
                return true;
            }
            if (!Yup::truthy($value)) {
                return false;
            }
            $values = is_array($value) ? $value : (is_object($value) && !Yup::isJsFunction($value) ? get_object_vars($value) : []);
            foreach ($values as $v) {
                if (!Yup::isJsFunction($v)) {
                    return false;
                }
            }

            return true;
        });
    }

    public function describe(): array
    {
        $base = parent::describe();
        $base['fields'] = array_map(static fn (?Yup $f): ?array => $f?->describe(), $this->fields);

        return $base;
    }
}
