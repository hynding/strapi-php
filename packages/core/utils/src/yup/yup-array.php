<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

use Strapi\Utils\Primitives\Objects;

/** `yup.array(of?)` */
class YupArray extends Yup
{
    protected string $type = 'array';

    protected ?Yup $innerType = null;

    public function __construct(?Yup $innerType = null)
    {
        parent::__construct();
        $this->innerType = $innerType;
    }

    public function innerType(): ?Yup
    {
        return $this->innerType;
    }

    protected function typeCheck(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    protected function typeTransform(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = YupObject::jsonParse($value, 'array');
        }

        return $this->isType($value) ? $value : null;
    }

    /** @param array<string, mixed> $options */
    protected function castValue(mixed $rawValue, array $options): mixed
    {
        $value = parent::castValue($rawValue, $options);
        if (!$this->typeCheck($value) || $this->innerType === null) {
            return $value;
        }
        /** @var list<mixed> $value */
        $isChanged = false;
        $castArray = [];
        foreach ($value as $idx => $v) {
            $castElement = $this->innerType->cast($v, [...$options, 'path' => ($options['path'] ?? '') . "[{$idx}]"]);
            if (!Yup::sameValue($castElement, $v) || (is_float($v) && is_nan($v))) {
                $isChanged = true;
            }
            $castArray[] = $castElement;
        }

        return $isChanged ? $castArray : $value;
    }

    protected function runValidation(mixed $value, array $options): array
    {
        $errors = [];
        $path = isset($options['path']) && is_string($options['path']) ? $options['path'] : null;
        $innerType = $this->innerType;
        $endEarly = (bool) ($options['abortEarly'] ?? $this->spec['abortEarly']);
        $recursive = (bool) ($options['recursive'] ?? $this->spec['recursive']);
        $originalValue = isset($options['originalValue']) && !($options['originalValue'] instanceof Undefined) ? $options['originalValue'] : $value;

        [$err, $value] = parent::runValidation($value, $options);
        if ($err !== null) {
            if ($endEarly) {
                return [$err, $value];
            }
            $errors[] = $err;
        }

        if (!$recursive || $innerType === null || !$this->typeCheck($value)) {
            return [$errors[0] ?? null, $value];
        }
        /** @var list<mixed> $value */
        $originalValue = Yup::truthy($originalValue) ? $originalValue : $value;

        $tests = [];
        foreach ($value as $idx => $item) {
            $innerOptions = [
                ...$options,
                'path' => ($options['path'] ?? '') . "[{$idx}]",
                'strict' => true,
                'parent' => $value,
                'index' => $idx,
                'originalValue' => is_array($originalValue) && array_key_exists($idx, $originalValue) ? $originalValue[$idx] : Undefined::value(),
            ];
            $tests[] = static fn (): ?YupError => $innerType->validateNested($item, $innerOptions)[0];
        }

        return [self::runTests($tests, $value, $path, $endEarly, $errors), $value];
    }

    public function concat(?Yup $schema): Yup
    {
        $next = parent::concat($schema);
        if ($next instanceof self) {
            $next->innerType = $this->innerType;
            if ($schema instanceof self && $schema->innerType !== null) {
                $next->innerType = $next->innerType !== null ? $next->innerType->concat($schema->innerType) : $schema->innerType;
            }
        }

        return $next;
    }

    public function of(Yup $schema): static
    {
        $next = clone $this;
        $next->innerType = $schema;

        return $next;
    }

    public function length(int|Reference $length, string|\Closure $message = Locale::ARRAY_LENGTH): static
    {
        return $this->test([
            'message' => $message,
            'name' => 'length',
            'exclusive' => true,
            'params' => ['length' => $length],
            'test' => static fn (mixed $value, TestContext $ctx): bool => Yup::isAbsent($value) || (is_array($value) && count($value) === $ctx->resolve($length)),
        ]);
    }

    public function min(int|Reference $min, string|\Closure|null $message = null): static
    {
        return $this->test([
            'message' => $message ?? Locale::ARRAY_MIN,
            'name' => 'min',
            'exclusive' => true,
            'params' => ['min' => $min],
            'test' => static fn (mixed $value, TestContext $ctx): bool => Yup::isAbsent($value) || (is_array($value) && count($value) >= $ctx->resolve($min)),
        ]);
    }

    public function max(int|Reference $max, string|\Closure|null $message = null): static
    {
        return $this->test([
            'message' => $message ?? Locale::ARRAY_MAX,
            'name' => 'max',
            'exclusive' => true,
            'params' => ['max' => $max],
            'test' => static fn (mixed $value, TestContext $ctx): bool => Yup::isAbsent($value) || (is_array($value) && count($value) <= $ctx->resolve($max)),
        ]);
    }

    /** Default `[]`; a non-array value becomes `[value]` (null → `[]`). */
    public function ensure(): static
    {
        return $this
            ->default(static fn (): array => [])
            ->transform(static fn (mixed $val, mixed $original): mixed => is_array($val) && array_is_list($val) ? $val : (Yup::isAbsent($original) ? [] : (is_array($original) && array_is_list($original) ? $original : [$original])));
    }

    /** Remove falsy values, or the ones `$rejector(value, index, array)` returns true for. */
    public function compact(?callable $rejector = null): static
    {
        return $this->transform(static function (mixed $values) use ($rejector): mixed {
            if (!is_array($values)) {
                return $values;
            }
            $out = [];
            foreach ($values as $i => $v) {
                $keep = $rejector === null ? Yup::truthy($v) : !Yup::truthy($rejector($v, $i, $values));
                if ($keep) {
                    $out[] = $v;
                }
            }

            return $out;
        });
    }

    /**
     * yup.ts: `.uniqueProperty(propertyName, message)` — an error at `path[index].propertyName` for
     * every element sharing its property value with another element.
     */
    public function uniqueProperty(string $propertyName, string|\Closure $message): static
    {
        return $this->test('unique', $message, static function (mixed $list, TestContext $ctx) use ($propertyName, $message): bool {
            if (!is_array($list)) {
                return true;
            }
            $sentinel = new \stdClass();
            $get = static function (mixed $element) use ($propertyName, $sentinel): mixed {
                $v = Objects::get($element, $propertyName, $sentinel);

                return $v === $sentinel ? Undefined::value() : $v;
            };

            $errors = [];
            foreach (array_values($list) as $index => $element) {
                $property = $get($element);
                $same = array_filter($list, static fn (mixed $e): bool => Yup::sameValue($get($e), $property));
                if (count($same) > 1) {
                    $errors[] = $ctx->createError(['path' => "{$ctx->path}[{$index}].{$propertyName}", 'message' => $message]);
                }
            }

            if ($errors !== []) {
                throw new YupError($errors);
            }

            return true;
        });
    }

    public function describe(): array
    {
        $base = parent::describe();
        if ($this->innerType !== null) {
            $base['innerType'] = $this->innerType->describe();
        }

        return $base;
    }
}
