<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/**
 * Port of packages/core/utils/src/traverse/factory.ts: a configurable traversal built from
 * interceptors (short-circuit a whole value), parsers (how to read/write a kind of value),
 * ignore predicates and attribute handlers (how to recurse into relations, components...).
 *
 * `traverse($visitor, $options, $data)` where $options is
 * `['schema' => Schema|array, 'getModel' => callable, 'path' => ?Path, 'parent' => ?ParentNode]`.
 *
 * @phpstan-type Model Schema|array<string, mixed>|null
 * @phpstan-type GetModel callable(string): (Schema|array<string, mixed>|null)
 * @phpstan-type TraverseOptions array{schema: Schema|array<string, mixed>|null, getModel: callable(string): (Schema|array<string, mixed>|null), path?: Path|null, parent?: ParentNode|null}
 * @phpstan-type Visitor callable(VisitorOptions, VisitorUtils): void
 * @phpstan-type Recurse \Closure(callable(VisitorOptions, VisitorUtils): void, TraverseOptions, mixed): mixed
 * @phpstan-type ParseUtils array{transform: callable(mixed): mixed, remove: callable(string, mixed): mixed, set: callable(string, mixed, mixed): mixed, keys: callable(mixed): list<string>, get: callable(string, mixed): mixed}
 */
final class Factory
{
    /** @var list<array{predicate: callable(mixed): bool, handler: callable}> */
    private array $interceptors = [];

    /** @var list<array{predicate: callable(mixed): bool, parser: callable(mixed): ParseUtils}> */
    private array $parsers = [];

    /** @var list<callable(Context): bool> */
    private array $ignore = [];

    /** @var list<array{predicate: callable(Context): bool, handler: callable(Context, TransformUtils): void}> */
    private array $attributeHandlers = [];

    /** @var list<array{predicate: callable(Context): bool, handler: callable(Context, TransformUtils): void}> */
    private array $commonHandlers = [];

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param Visitor $visitor
     * @param TraverseOptions $options
     */
    public function traverse(callable $visitor, array $options, mixed $data): mixed
    {
        $path = $options['path'] ?? new Path(null, null, null);
        $parent = $options['parent'] ?? null;
        $schema = $options['schema'] ?? null;
        $getModel = $options['getModel'];

        $recurse = $this->traverse(...);

        // interceptors
        foreach ($this->interceptors as ['predicate' => $predicate, 'handler' => $handler]) {
            if ($predicate($data)) {
                return $handler($visitor, $options, $data, $recurse);
            }
        }

        // parsers
        $utils = null;
        foreach ($this->parsers as ['predicate' => $predicate, 'parser' => $parser]) {
            if ($predicate($data)) {
                $utils = $parser($data);
                break;
            }
        }

        // Return the data untouched if we don't know how to traverse it
        if ($utils === null) {
            return $data;
        }

        // main loop
        $out = $utils['transform']($data);
        $keys = $utils['keys']($out);

        foreach ($keys as $key) {
            $attribute = ContentTypes::attribute($schema, $key);

            $newPath = new Path(
                $path->raw === null ? $key : "{$path->raw}.{$key}",
                $attribute !== null ? ($path->attribute === null ? $key : "{$path->attribute}.{$key}") : $path->attribute,
                $path->rawWithIndices,
            );

            $visitorUtils = new VisitorUtils(
                $out,
                static fn (string $k, mixed $v, mixed $d): mixed => $utils['set']($k, $v, $d),
                static fn (string $k, mixed $d): mixed => $utils['remove']($k, $d),
            );

            $visitor(new VisitorOptions(
                data: $out,
                schema: $schema,
                key: $key,
                value: $utils['get']($key, $out),
                attribute: $attribute,
                path: $newPath,
                getModel: $getModel,
                parent: $parent,
            ), $visitorUtils);

            $out = $visitorUtils->data();

            $value = $utils['get']($key, $out);

            $createContext = static fn (): Context => new Context(
                key: $key,
                value: $value,
                attribute: $attribute,
                schema: $schema,
                path: $newPath,
                data: $out,
                visitor: $visitor,
                getModel: $getModel,
                parent: $parent,
            );

            // ignore
            $ignoreCtx = $createContext();
            foreach ($this->ignore as $predicate) {
                if ($predicate($ignoreCtx)) {
                    continue 2;
                }
            }

            // handlers
            foreach ([...$this->commonHandlers, ...$this->attributeHandlers] as ['predicate' => $predicate, 'handler' => $handler]) {
                $ctx = $createContext();

                if ($predicate($ctx)) {
                    $transformUtils = new TransformUtils(
                        static function (string $k, mixed $v) use (&$out, $utils): void {
                            $out = $utils['set']($k, $v, $out);
                        },
                        $recurse,
                    );
                    $handler($ctx, $transformUtils);
                }
            }
        }

        return $out;
    }

    /**
     * @param callable(mixed): bool $predicate
     * @param callable(Visitor, TraverseOptions, mixed, Recurse): mixed $handler
     */
    public function intercept(callable $predicate, callable $handler): self
    {
        $this->interceptors[] = ['predicate' => $predicate, 'handler' => $handler];

        return $this;
    }

    /**
     * @param callable(mixed): bool $predicate
     * @param callable(mixed): ParseUtils $parser
     */
    public function parse(callable $predicate, callable $parser): self
    {
        $this->parsers[] = ['predicate' => $predicate, 'parser' => $parser];

        return $this;
    }

    /** @param callable(Context): bool $predicate */
    public function ignore(callable $predicate): self
    {
        $this->ignore[] = $predicate;

        return $this;
    }

    /**
     * @param callable(Context): bool $predicate
     * @param callable(Context, TransformUtils): void $handler
     */
    public function on(callable $predicate, callable $handler): self
    {
        $this->commonHandlers[] = ['predicate' => $predicate, 'handler' => $handler];

        return $this;
    }

    /**
     * @param callable(Context): bool $predicate
     * @param callable(Context, TransformUtils): void $handler
     */
    public function onAttribute(callable $predicate, callable $handler): self
    {
        $this->attributeHandlers[] = ['predicate' => $predicate, 'handler' => $handler];

        return $this;
    }

    /** @param callable(Context, TransformUtils): void $handler */
    public function onRelation(callable $handler): self
    {
        return $this->onAttribute(static fn (Context $ctx): bool => ($ctx->attribute['type'] ?? null) === 'relation', $handler);
    }

    /** @param callable(Context, TransformUtils): void $handler */
    public function onMedia(callable $handler): self
    {
        return $this->onAttribute(static fn (Context $ctx): bool => ($ctx->attribute['type'] ?? null) === 'media', $handler);
    }

    /** @param callable(Context, TransformUtils): void $handler */
    public function onComponent(callable $handler): self
    {
        return $this->onAttribute(static fn (Context $ctx): bool => ($ctx->attribute['type'] ?? null) === 'component', $handler);
    }

    /** @param callable(Context, TransformUtils): void $handler */
    public function onDynamicZone(callable $handler): self
    {
        return $this->onAttribute(static fn (Context $ctx): bool => ($ctx->attribute['type'] ?? null) === 'dynamiczone', $handler);
    }

    /**
     * The object parser shared by every query traversal: associative arrays read/written by key.
     *
     * @return ParseUtils
     */
    public static function objectParser(): array
    {
        return [
            'transform' => static fn (mixed $value): mixed => $value,
            'remove' => static function (string $key, mixed $data): mixed {
                if (is_array($data)) {
                    unset($data[$key]);
                }

                return $data;
            },
            'set' => static function (string $key, mixed $value, mixed $data): mixed {
                if (is_array($data)) {
                    $data[$key] = $value;
                }

                return $data;
            },
            'keys' => static fn (mixed $data): array => is_array($data) ? array_map('strval', array_keys($data)) : [],
            'get' => static fn (string $key, mixed $data): mixed => is_array($data) ? ($data[$key] ?? null) : null,
        ];
    }

    /** `_.isObject` for query values: arrays only (query params are never PHP objects). */
    public static function isObj(mixed $value): bool
    {
        return is_array($value);
    }

    /** A list whose every item is a string. */
    public static function isStringArray(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /** A list whose every item is an array ("object"). */
    public static function isObjectArray(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return true;
    }
}
