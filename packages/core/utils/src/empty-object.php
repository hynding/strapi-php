<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * PHP port only (no upstream file): a JSON `{}`.
 *
 * `json_decode($json, true)` turns both `{}` and `[]` into a PHP `[]`. Non-empty containers stay
 * distinguishable (`array_is_list`), the empty ones do not, and validation that must tell an
 * object from an array (a single component sent as `[]`, a repeatable one sent as `{}`) cannot.
 * {@see self::decode()} decodes an empty JSON object to this marker instead; everything else
 * decodes as with `json_decode($json, true)`.
 *
 * Readers that index into it keep working: `$v['key'] ?? null`, `isset()`, `count()`, `foreach`,
 * `(array) $v` (gives `[]`). It is immutable (writing a key throws: copy it to an array first,
 * see {@see self::toArrays()}), JS-truthy like `{}`, and serializes back as `{}`.
 *
 * Request bodies carry it only where a controller asks for it (`$ctx->requestBody(true)`: the
 * content API and content-manager document actions, then filtered by keepInDocumentData());
 * `$ctx->requestBody()` gives the body with every marker turned back into `[]`. See
 * docs/empty-json-objects.md.
 *
 * @implements \ArrayAccess<array-key, mixed>
 * @implements \IteratorAggregate<array-key, mixed>
 */
final class EmptyObject implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    public function offsetExists(mixed $offset): bool
    {
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Strapi\Utils\EmptyObject is immutable: convert it with EmptyObject::toArrays() before writing to it');
    }

    public function offsetUnset(mixed $offset): void
    {
        // nothing to remove
    }

    public function count(): int
    {
        return 0;
    }

    public function getIterator(): \EmptyIterator
    {
        return new \EmptyIterator();
    }

    public function jsonSerialize(): \stdClass
    {
        return new \stdClass();
    }

    /**
     * `json_decode($json, true, $depth, $flags)`, except that an empty JSON object becomes an
     * {@see EmptyObject}. Documents without an empty object (no `{` followed by `}`) take the
     * plain decoder.
     *
     * @param int<1, max> $depth
     * @throws \JsonException with JSON_THROW_ON_ERROR
     */
    public static function decode(string $json, int $depth = 512, int $flags = 0): mixed
    {
        $flags &= ~JSON_OBJECT_AS_ARRAY;
        if (preg_match('/\{\s*\}/', $json) !== 1) {
            return json_decode($json, true, $depth, $flags);
        }

        return self::fromStdClass(json_decode($json, false, $depth, $flags));
    }

    private static function fromStdClass(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return new self();
            }
            $value = $vars;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_array($item) || $item instanceof \stdClass) {
                    $value[$key] = self::fromStdClass($item);
                }
            }
        }

        return $value;
    }

    /** Whether `$value` is (or holds, at any depth) an {@see EmptyObject}. */
    public static function contains(mixed $value): bool
    {
        if ($value instanceof self) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (($item instanceof self || is_array($item)) && self::contains($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every {@see EmptyObject} in `$value` (at any depth) replaced by `[]`: the body as
     * `json_decode($json, true)` gives it. Values without a marker are returned as they are.
     */
    public static function toArrays(mixed $value): mixed
    {
        if ($value instanceof self) {
            return [];
        }
        if (is_array($value)) {
            self::lower($value);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return bool whether a marker was replaced (the array is only written to, and so copied, then)
     */
    private static function lower(array &$value): bool
    {
        $changed = false;
        foreach ($value as $key => $item) {
            if ($item instanceof self) {
                $value[$key] = [];
                $changed = true;
            } elseif (is_array($item) && $item !== [] && self::lower($item)) {
                $value[$key] = $item;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Document data with markers kept only where the entity validator tells `{}` from `[]` and
     * where `{}` is stored as such: component and dynamic-zone values (at any depth) and `json`
     * attributes. Everywhere else (relation payloads such as `{ options: {}, connect: [] }`, media,
     * unknown keys) they become `[]`, as `$ctx->requestBody()` gives them, since the sanitize and
     * validate visitors read those values as arrays.
     *
     * @param \Strapi\Types\Schema\Schema|array<string, mixed>|null $schema
     * @param callable(string): (\Strapi\Types\Schema\Schema|array<string, mixed>|null) $getModel
     */
    public static function keepInDocumentData(mixed $data, \Strapi\Types\Schema\Schema|array|null $schema, callable $getModel): mixed
    {
        if ($data instanceof self) {
            return $data;
        }
        if (!is_array($data) || $schema === null || array_is_list($data)) {
            return self::toArrays($data);
        }
        foreach ($data as $key => $value) {
            if (!$value instanceof self && !is_array($value)) {
                continue;
            }
            $attribute = ContentTypes::attribute($schema, (string) $key);
            $type = $attribute['type'] ?? null;
            if ($type === 'json') {
                continue;
            }
            if ($type === 'component') {
                $model = $getModel((string) ($attribute['component'] ?? ''));
                $data[$key] = is_array($value) && array_is_list($value)
                    ? array_map(static fn (mixed $item): mixed => self::keepInDocumentData($item, $model, $getModel), $value)
                    : self::keepInDocumentData($value, $model, $getModel);
            } elseif ($type === 'dynamiczone' && is_array($value) && array_is_list($value)) {
                $data[$key] = array_map(static function (mixed $item) use ($getModel): mixed {
                    $uid = is_array($item) ? ($item['__component'] ?? null) : null;

                    return self::keepInDocumentData($item, is_string($uid) ? $getModel($uid) : null, $getModel);
                }, $value);
            } elseif ($type === 'dynamiczone') {
                continue; // a `{}` sent for a dynamic zone: the validator rejects it
            } else {
                $data[$key] = self::toArrays($value);
            }
        }

        return $data;
    }

    /**
     * A JS object: an associative array, an {@see EmptyObject}, or `[]` when `$emptyArrayIsObject`
     * (values that did not come from a JSON body, where `[]` is ambiguous).
     */
    public static function isObject(mixed $value, bool $emptyArrayIsObject = true): bool
    {
        if ($value instanceof self) {
            return true;
        }

        return is_array($value) && (!array_is_list($value) || ($emptyArrayIsObject && $value === []));
    }
}
