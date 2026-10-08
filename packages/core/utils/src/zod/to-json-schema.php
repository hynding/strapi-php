<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.toJSONSchema(schema | registry, params)`, best effort: it follows zod's processors for the
 * supported schema types closely enough for OpenAPI generation, but is not a byte-for-byte port.
 *
 * Params: `target` (`draft-2020-12` default, `draft-07`, `draft-04`, `openapi-3.0`), `io`
 * (`output` default, or `input`), `unrepresentable` (`throw` default, or `any`), `metadata` (a
 * {@see ZodRegistry} whose `id`s become `$defs` references) and, for a registry, `uri`
 * (`fn(string $id): string`, the `$ref`/`$id` of each schema).
 *
 * Recursive schemas (through `z.lazy()`) become `$ref`s: `#` for the root, `#/$defs/__schemaN`
 * otherwise. Empty schemas (`z.any()`, `z.unknown()`) are `stdClass` so `json_encode` writes `{}`.
 */
final class ToJsonSchema
{
    private string $target;

    private string $io;

    private bool $throwUnrepresentable;

    private ?ZodRegistry $metadata;

    /** @var \Closure(string): string */
    private \Closure $uri;

    /** @var array<int, true> schemas being processed, by object id */
    private array $stack = [];

    /** @var array<int, string> cycle definition ids, by object id */
    private array $cycles = [];

    /** @var array<string, array<string, mixed>|\stdClass> */
    private array $defs = [];

    private ?ZodType $root = null;

    private ?string $rootId = null;

    /** @param array<string, mixed> $params */
    private function __construct(array $params)
    {
        $this->target = is_string($params['target'] ?? null) ? $params['target'] : 'draft-2020-12';
        $this->io = ($params['io'] ?? 'output') === 'input' ? 'input' : 'output';
        $this->throwUnrepresentable = ($params['unrepresentable'] ?? 'throw') !== 'any';
        $this->metadata = ($params['metadata'] ?? null) instanceof ZodRegistry ? $params['metadata'] : null;
        $uri = $params['uri'] ?? null;
        $this->uri = $uri instanceof \Closure ? $uri : static fn (string $id): string => $id;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function generate(ZodType|ZodRegistry $input, array $params = []): array
    {
        if ($input instanceof ZodRegistry) {
            $schemas = [];
            $hasUri = ($params['uri'] ?? null) instanceof \Closure;
            foreach ($input->all() as [$schema, $meta]) {
                if (!isset($meta['id']) || !is_string($meta['id'])) {
                    continue;
                }
                $generator = new self(['metadata' => $input] + $params);
                $generator->rootId = $meta['id'];
                $json = $generator->root($schema);
                $head = $generator->schemaHeader();
                if ($hasUri) {
                    $head['$id'] = ($generator->uri)($meta['id']);
                }
                $schemas[$meta['id']] = $head + (array) $json + $generator->definitions();
            }

            return ['schemas' => $schemas];
        }

        $generator = new self($params);
        $json = $generator->root($input);

        return $generator->schemaHeader() + (array) $json + $generator->definitions();
    }

    /** @return array<string, mixed>|\stdClass */
    private function root(ZodType $schema): array|\stdClass
    {
        $this->root = self::resolve($schema);

        return $this->process($schema, true);
    }

    /** @return array<string, string> */
    private function schemaHeader(): array
    {
        return match ($this->target) {
            'draft-2020-12' => ['$schema' => 'https://json-schema.org/draft/2020-12/schema'],
            'draft-07' => ['$schema' => 'http://json-schema.org/draft-07/schema#'],
            'draft-04' => ['$schema' => 'http://json-schema.org/draft-04/schema#'],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function definitions(): array
    {
        if ($this->defs === []) {
            return [];
        }

        return [in_array($this->target, ['draft-07', 'draft-04'], true) ? 'definitions' : '$defs' => $this->defs];
    }

    private function defRef(string $id): string
    {
        return (in_array($this->target, ['draft-07', 'draft-04'], true) ? '#/definitions/' : '#/$defs/') . $id;
    }

    private static function resolve(ZodType $schema): ZodType
    {
        $seen = [];
        while ($schema instanceof ZodLazy && !isset($seen[spl_object_id($schema)])) {
            $seen[spl_object_id($schema)] = true;
            $schema = $schema->unwrap();
        }

        return $schema;
    }

    /** @return array<string, mixed>|\stdClass */
    private function process(ZodType $schema, bool $isRoot = false): array|\stdClass
    {
        $target = self::resolve($schema);
        $oid = spl_object_id($target);

        // Schemas registered with an id in the metadata registry are referenced, not inlined.
        $meta = $this->metadata?->get($target);
        $id = is_string($meta['id'] ?? null) ? $meta['id'] : null;
        if ($id !== null && !($isRoot && $target === $this->root)) {
            if ($this->rootId !== null) {
                return ['$ref' => ($this->uri)($id)];
            }
            if (!isset($this->defs[$id])) {
                $this->defs[$id] = new \stdClass(); // placeholder against cycles
                $this->defs[$id] = $this->build($target);
            }

            return ['$ref' => $this->defRef($id)];
        }

        if (isset($this->stack[$oid])) {
            if ($target === $this->root) {
                return ['$ref' => $this->rootId !== null ? ($this->uri)($this->rootId) : '#'];
            }
            $this->cycles[$oid] ??= '__schema' . count($this->cycles);

            return ['$ref' => $this->defRef($this->cycles[$oid])];
        }

        $this->stack[$oid] = true;
        $json = $this->build($target);
        unset($this->stack[$oid]);

        if (isset($this->cycles[$oid]) && $target !== $this->root) {
            $this->defs[$this->cycles[$oid]] = $json;

            return ['$ref' => $this->defRef($this->cycles[$oid])];
        }

        return $json;
    }

    /** @return array<string, mixed>|\stdClass */
    private function build(ZodType $schema): array|\stdClass
    {
        $json = $this->buildType($schema);
        $metadata = $schema->meta();
        if (is_array($metadata)) {
            unset($metadata['id']);
            $json = $json + $metadata;
        }
        $registryMeta = $this->metadata?->get($schema);
        if ($registryMeta !== null) {
            unset($registryMeta['id']);
            $json = $json + $registryMeta;
        }

        return $json === [] ? new \stdClass() : $json;
    }

    /** @return array<string, mixed> */
    private function buildType(ZodType $schema): array
    {
        return match (true) {
            $schema instanceof ZodString => $this->string($schema),
            $schema instanceof ZodNumber => $this->number($schema),
            $schema instanceof ZodBoolean => ['type' => 'boolean'],
            $schema instanceof ZodNull => $this->target === 'openapi-3.0' ? ['type' => 'string', 'nullable' => true, 'enum' => [null]] : ['type' => 'null'],
            $schema instanceof ZodAny, $schema instanceof ZodUnknown => [],
            $schema instanceof ZodNever => ['not' => new \stdClass()],
            $schema instanceof ZodLiteral => $this->literal($schema->values()),
            $schema instanceof ZodEnum => $this->enum($schema->values()),
            $schema instanceof ZodArray => $this->arrayType($schema),
            $schema instanceof ZodObject => $this->object($schema),
            $schema instanceof ZodDiscriminatedUnion => ['oneOf' => array_map(fn (ZodType $o) => $this->process($o), $schema->options())],
            $schema instanceof ZodUnion => ['anyOf' => array_map(fn (ZodType $o) => $this->process($o), $schema->options())],
            $schema instanceof ZodIntersection => ['allOf' => [$this->process($schema->left()), $this->process($schema->right())]],
            $schema instanceof ZodRecord => $this->record($schema),
            $schema instanceof ZodTuple => $this->tuple($schema),
            $schema instanceof ZodOptional, $schema instanceof ZodNonOptional => (array) $this->process($schema->unwrap()),
            $schema instanceof ZodNullable => $this->nullable($schema),
            $schema instanceof ZodDefault => ['default' => $schema->defaultValue()] + (array) $this->process($schema->unwrap()),
            $schema instanceof ZodReadonly => ['readOnly' => true] + (array) $this->process($schema->unwrap()),
            $schema instanceof ZodPipe => (array) $this->process($this->io === 'input'
                ? ($schema->in() instanceof ZodTransform ? $schema->out() : $schema->in())
                : $schema->out()),
            $schema instanceof ZodTransform => $this->unrepresentable('Transforms cannot be represented in JSON Schema'),
            $schema instanceof ZodCustom => $this->unrepresentable('Custom types cannot be represented in JSON Schema'),
            default => $this->unrepresentable(get_class($schema) . ' cannot be represented in JSON Schema'),
        };
    }

    /** @return array<string, mixed> */
    private function unrepresentable(string $message): array
    {
        if ($this->throwUnrepresentable) {
            throw new \LogicException($message);
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function string(ZodString $schema): array
    {
        $json = ['type' => 'string'];
        $minimum = null;
        $maximum = null;
        $format = null;
        $patterns = [];
        foreach ($schema->checks() as $check) {
            $def = $check->def;
            switch ($check->kind()) {
                case 'min_length':
                    $minimum = max($minimum ?? PHP_INT_MIN, (int) $def['minimum']);
                    break;
                case 'max_length':
                    $maximum = min($maximum ?? PHP_INT_MAX, (int) $def['maximum']);
                    break;
                case 'length_equals':
                    $minimum = $maximum = (int) $def['length'];
                    break;
                case 'string_format':
                    $format = $def['format'];
                    if (is_string($def['pattern'] ?? null) && !in_array($def['pattern'], $patterns, true)) {
                        $patterns[] = $def['pattern'];
                    }
                    break;
            }
        }
        if ($minimum !== null) {
            $json['minLength'] = $minimum;
        }
        if ($maximum !== null) {
            $json['maxLength'] = $maximum;
        }
        if (is_string($format) && !in_array($format, ['regex', 'time', 'starts_with', 'ends_with', 'includes'], true)) {
            $json['format'] = ['guid' => 'uuid', 'url' => 'uri', 'datetime' => 'date-time', 'json_string' => 'json-string'][$format] ?? $format;
        }
        if (count($patterns) === 1) {
            $json['pattern'] = self::regexSource($patterns[0]);
        } elseif (count($patterns) > 1) {
            $json['allOf'] = array_map(
                fn (string $p): array => (in_array($this->target, ['draft-07', 'draft-04', 'openapi-3.0'], true) ? ['type' => 'string'] : []) + ['pattern' => self::regexSource($p)],
                $patterns,
            );
        }

        return $json;
    }

    /** `/source/flags` → `source` */
    private static function regexSource(string $pattern): string
    {
        $delimiter = $pattern[0];
        $end = strrpos($pattern, $delimiter);

        return $end === false || $end === 0 ? $pattern : substr($pattern, 1, $end - 1);
    }

    /** @return array<string, mixed> */
    private function number(ZodNumber $schema): array
    {
        $bag = [];
        $int = false;
        foreach ($schema->checks() as $check) {
            $def = $check->def;
            switch ($check->kind()) {
                case 'number_format':
                    $int = true;
                    $bag['minimum'] = -9007199254740991;
                    $bag['maximum'] = 9007199254740991;
                    break;
                case 'greater_than':
                    $key = $def['inclusive'] ? 'minimum' : 'exclusiveMinimum';
                    if (!isset($bag[$key]) || $def['value'] > $bag[$key]) {
                        $bag[$key] = $def['value'];
                    }
                    break;
                case 'less_than':
                    $key = $def['inclusive'] ? 'maximum' : 'exclusiveMaximum';
                    if (!isset($bag[$key]) || $def['value'] < $bag[$key]) {
                        $bag[$key] = $def['value'];
                    }
                    break;
                case 'multiple_of':
                    $bag['multipleOf'] ??= $def['value'];
                    break;
            }
        }
        $json = ['type' => $int ? 'integer' : 'number'];
        $legacy = in_array($this->target, ['draft-04', 'openapi-3.0'], true);
        if (isset($bag['exclusiveMinimum']) && $bag['exclusiveMinimum'] >= ($bag['minimum'] ?? -INF)) {
            $json += $legacy ? ['minimum' => $bag['exclusiveMinimum'], 'exclusiveMinimum' => true] : ['exclusiveMinimum' => $bag['exclusiveMinimum']];
        } elseif (isset($bag['minimum'])) {
            $json['minimum'] = $bag['minimum'];
        }
        if (isset($bag['exclusiveMaximum']) && $bag['exclusiveMaximum'] <= ($bag['maximum'] ?? INF)) {
            $json += $legacy ? ['maximum' => $bag['exclusiveMaximum'], 'exclusiveMaximum' => true] : ['exclusiveMaximum' => $bag['exclusiveMaximum']];
        } elseif (isset($bag['maximum'])) {
            $json['maximum'] = $bag['maximum'];
        }
        if (isset($bag['multipleOf'])) {
            $json['multipleOf'] = $bag['multipleOf'];
        }

        return $json;
    }

    private static function jsType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_string($value) => 'string',
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            default => 'object',
        };
    }

    /**
     * @param list<mixed> $values
     *
     * @return array<string, mixed>
     */
    private function literal(array $values): array
    {
        $values = array_values(array_filter($values, static fn (mixed $v): bool => $v !== Undefined::Value));
        if ($values === []) {
            return [];
        }
        if (count($values) === 1) {
            $json = ['type' => self::jsType($values[0])];
            if (in_array($this->target, ['draft-04', 'openapi-3.0'], true)) {
                $json['enum'] = $values;
            } else {
                $json['const'] = $values[0];
            }

            return $json;
        }
        $json = [];
        $types = array_unique(array_map(self::jsType(...), $values));
        if (count($types) === 1 && in_array($types[0], ['number', 'string', 'boolean', 'null'], true)) {
            $json['type'] = $types[0];
        }
        $json['enum'] = $values;

        return $json;
    }

    /**
     * @param list<mixed> $values
     *
     * @return array<string, mixed>
     */
    private function enum(array $values): array
    {
        $json = [];
        $types = array_unique(array_map(self::jsType(...), $values));
        if (count($types) === 1 && in_array($types[0], ['number', 'string'], true)) {
            $json['type'] = $types[0];
        }
        $json['enum'] = $values;

        return $json;
    }

    /** @return array<string, mixed> */
    private function arrayType(ZodArray $schema): array
    {
        $json = [];
        foreach ($schema->checks() as $check) {
            $def = $check->def;
            match ($check->kind()) {
                'min_length' => $json['minItems'] = max($json['minItems'] ?? PHP_INT_MIN, (int) $def['minimum']),
                'max_length' => $json['maxItems'] = min($json['maxItems'] ?? PHP_INT_MAX, (int) $def['maximum']),
                'length_equals' => $json['minItems'] = $json['maxItems'] = (int) $def['length'],
                default => null,
            };
        }

        return $json + ['type' => 'array', 'items' => $this->process($schema->element())];
    }

    /** @return array<string, mixed> */
    private function object(ZodObject $schema): array
    {
        $properties = [];
        $required = [];
        foreach ($schema->shape() as $key => $field) {
            $properties[$key] = $this->process($field);
            $optional = $this->io === 'input' ? $field->isOptionalIn() : $field->isOptionalOut();
            if (!$optional) {
                $required[] = (string) $key;
            }
        }
        $json = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $json['required'] = $required;
        }
        $catchall = $schema->getCatchall();
        if ($catchall instanceof ZodNever) {
            $json['additionalProperties'] = false;
        } elseif ($catchall === null) {
            if ($this->io === 'output') {
                $json['additionalProperties'] = false;
            }
        } else {
            $json['additionalProperties'] = $this->process($catchall);
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function record(ZodRecord $schema): array
    {
        $json = ['type' => 'object'];
        if (in_array($this->target, ['draft-07', 'draft-2020-12'], true)) {
            $json['propertyNames'] = $this->process($schema->keyType());
        }
        $json['additionalProperties'] = $this->process($schema->valueType());
        $values = $schema->isPartial() ? null : $schema->keyType()->values();
        if ($values !== null) {
            $keys = array_values(array_filter($values, static fn (mixed $v): bool => is_string($v) || is_int($v) || is_float($v)));
            if ($keys !== []) {
                $json['required'] = $keys;
            }
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function tuple(ZodTuple $schema): array
    {
        $items = array_map(fn (ZodType $item) => $this->process($item), $schema->items());
        $rest = $schema->getRest();
        if ($this->target === 'draft-2020-12') {
            $json = ['type' => 'array', 'prefixItems' => $items];
            if ($rest !== null) {
                $json['items'] = $this->process($rest);
            }
        } else {
            $json = ['type' => 'array', 'items' => $items];
            if ($rest !== null) {
                $json['additionalItems'] = $this->process($rest);
            }
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function nullable(ZodNullable $schema): array
    {
        $inner = $this->process($schema->unwrap());
        if ($this->target === 'openapi-3.0') {
            return ['nullable' => true] + (array) $inner;
        }

        return ['anyOf' => [$inner, ['type' => 'null']]];
    }
}
