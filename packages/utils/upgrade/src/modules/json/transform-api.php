<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Json;

/**
 * Port of packages/utils/upgrade/src/modules/json/transform-api.ts: the `json()` helper handed to
 * JSON codemods, wrappers over lodash/fp's `get`, `has`, `set`, `merge` and `omit` on a private
 * deep copy. Paths use lodash's syntax (`dependencies.@strapi/strapi`, `a[0].b`, `a["x.y"]`).
 *
 * @phpstan-import-type JSONObject from Types
 */
final class JSONTransformAPI
{
    /** @var JSONObject */
    private array $json;

    /** @param JSONObject $json */
    public function __construct(array $json)
    {
        $this->json = array_map(self::cloneDeep(...), $json);
    }

    /** @param JSONObject $object */
    public static function createJSONTransformAPI(array $object): self
    {
        return new self($object);
    }

    public function get(?string $path = null, mixed $defaultValue = null): mixed
    {
        if ($path === null || $path === '') {
            return $this->root();
        }

        return self::cloneDeep(self::getPath($this->json, self::toPath($path)) ?? $defaultValue);
    }

    public function has(string $path): bool
    {
        $current = $this->json;
        foreach (self::toPath($path) as $key) {
            if ($current instanceof \stdClass) {
                $current = get_object_vars($current);
            }
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return false;
            }
            $current = $current[$key];
        }

        return true;
    }

    /** @param JSONObject $other */
    public function merge(array $other): self
    {
        // lodash/fp `merge(other, json)`: `json` merged over a copy of `other`
        $merged = self::mergeDeep(array_map(self::cloneDeep(...), $other), $this->json);
        $this->json = is_array($merged) ? $merged : $this->json;

        return $this;
    }

    /** @return JSONObject */
    public function root(): array
    {
        return array_map(self::cloneDeep(...), $this->json);
    }

    public function set(string $path, mixed $value): self
    {
        $this->json = self::setPath($this->json, self::toPath($path), self::cloneDeep($value));

        return $this;
    }

    public function remove(string $path): self
    {
        $this->json = self::omitPath($this->json, self::toPath($path));

        return $this;
    }

    /**
     * lodash `stringToPath`
     *
     * @return list<string>
     */
    public static function toPath(string $path): array
    {
        $result = [];
        if (str_starts_with($path, '.')) {
            $result[] = '';
        }
        preg_match_all('/[^.[\]]+|\[(?:([^"\'][^[]*)|(["\'])((?:(?!\2)[^\\\\]|\\\\.)*?)\2)\]|(?=(?:\.|\[\])(?:\.|\[\]|$))/', $path, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            if (isset($m[2]) && $m[2] !== '') {
                $result[] = (string) preg_replace('/\\\\(\\\\)?/', '$1', $m[3] ?? '');
            } elseif (isset($m[1]) && $m[1] !== '') {
                $result[] = $m[1];
            } else {
                $result[] = $m[0];
            }
        }

        return $result;
    }

    /** @param list<string> $path */
    private static function getPath(mixed $value, array $path): mixed
    {
        foreach ($path as $key) {
            if ($value instanceof \stdClass) {
                $value = get_object_vars($value);
            }
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param list<string> $path
     * @return array<array-key, mixed>
     */
    private static function setPath(mixed $value, array $path, mixed $newValue): array
    {
        $object = $value instanceof \stdClass ? get_object_vars($value) : (is_array($value) ? $value : []);
        $key = array_shift($path);
        if ($key === null) {
            return $object;
        }

        $object[$key] = $path === [] ? $newValue : self::setPath($object[$key] ?? null, $path, $newValue);

        return $object;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $path
     * @return array<array-key, mixed>
     */
    private static function omitPath(array $value, array $path): array
    {
        $key = array_shift($path);
        if ($key === null || !array_key_exists($key, $value)) {
            return $value;
        }

        if ($path === []) {
            unset($value[$key]);

            return $value;
        }

        $child = $value[$key];
        if ($child instanceof \stdClass) {
            return $value;
        }
        if (is_array($child)) {
            $omitted = self::omitPath($child, $path);
            $value[$key] = $omitted === [] && $child !== [] ? new \stdClass() : $omitted;
        }

        return $value;
    }

    private static function mergeDeep(mixed $object, mixed $source): mixed
    {
        if ($source instanceof \stdClass) {
            return is_array($object) || $object instanceof \stdClass ? $object : $source;
        }
        if (!is_array($source)) {
            return $source;
        }
        if ($object instanceof \stdClass) {
            $object = [];
        }
        if (!is_array($object)) {
            return $source;
        }

        foreach ($source as $key => $value) {
            $object[$key] = array_key_exists($key, $object) ? self::mergeDeep($object[$key], $value) : $value;
        }

        return $object;
    }

    /** Deep copy (arrays are copied by value; `\stdClass` instances are cloned). */
    public static function cloneDeep(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return clone $value;
        }
        if (is_array($value)) {
            return array_map(self::cloneDeep(...), $value);
        }

        return $value;
    }

    /** lodash `isEqual` for JSON values: object key order is ignored, numbers compare by value */
    public static function isEqual(mixed $a, mixed $b): bool
    {
        if ($a instanceof \stdClass) {
            $a = get_object_vars($a);
        }
        if ($b instanceof \stdClass) {
            $b = get_object_vars($b);
        }

        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::isEqual($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }
}
