<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

use Strapi\Utils\Errors\YupValidationError;
use Strapi\Utils\PrintValue;

/**
 * Not an upstream file: the subset of `yup` (v0.32, as pinned by @strapi/utils) the entity
 * validator uses — `mixed/string/number/boolean/array/object/lazy`, `required/nullable/notNil/notNull`,
 * `min/max/matches/email/oneOf/integer/test/transform/default/concat/strict`, non-strict casting,
 * `abortEarly: false` error collection and yup's default messages, including the `notType` message
 * set by @strapi/utils yup.ts.
 *
 * Validation: `$schema->validate($value, ['strict' => false, 'abortEarly' => false])` returns the
 * cast value or throws {@see YupValidationError} with `details.errors[] = { path, message, name, value }`.
 */
class Yup
{
    protected string $type = 'mixed';

    protected bool $nullable = false;

    protected bool $optional = true;

    /** @var list<array{name: string, message: string|\Closure, test: \Closure}> */
    protected array $tests = [];

    /** @var list<\Closure(mixed, mixed): mixed> value, originalValue → value */
    protected array $transforms = [];

    protected mixed $default = null;

    protected bool $hasDefault = false;

    protected bool $strict = false;

    /** @var list<array{path: string, message: string, value: mixed}> */
    private array $collected = [];

    // --- factories -----------------------------------------------------------------------------

    public static function mixed(): self
    {
        return new self();
    }

    public static function string(): YupString
    {
        return new YupString();
    }

    public static function number(): YupNumber
    {
        return new YupNumber();
    }

    public static function boolean(): YupBoolean
    {
        return new YupBoolean();
    }

    public static function array(): YupArray
    {
        return new YupArray();
    }

    /** @param array<string, Yup> $shape */
    public static function object(array $shape = []): YupObject
    {
        return (new YupObject())->shape($shape);
    }

    /** @param callable(mixed): Yup $resolver */
    public static function lazy(callable $resolver): YupLazy
    {
        return new YupLazy($resolver);
    }

    // --- modifiers ----------------------------------------------------------------------------

    public function type(): string
    {
        return $this->type;
    }

    public function nullable(bool $nullable = true): static
    {
        $clone = clone $this;
        $clone->nullable = $nullable;

        return $clone;
    }

    public function required(string $message = '${path} is a required field'): static
    {
        $clone = clone $this;
        $clone->optional = false;
        $clone->tests[] = ['name' => 'required', 'message' => $message, 'test' => fn (mixed $v): bool => $this->isPresent($v)];

        return $clone;
    }

    public function notRequired(): static
    {
        $clone = clone $this;
        $clone->optional = true;
        $clone->tests = array_values(array_filter($clone->tests, static fn (array $t): bool => $t['name'] !== 'required'));

        return $clone;
    }

    /** yup.ts: `.notNil()` */
    public function notNil(string $message = '${path} must be defined.'): static
    {
        return $this->test('defined', $message, static fn (mixed $v): bool => $v !== null && !($v instanceof Undefined));
    }

    /** yup.ts: `.notNull()` */
    public function notNull(string $message = '${path} cannot be null.'): static
    {
        return $this->test('defined', $message, static fn (mixed $v): bool => $v !== null);
    }

    public function strict(bool $strict = true): static
    {
        $clone = clone $this;
        $clone->strict = $strict;

        return $clone;
    }

    public function default(mixed $value): static
    {
        $clone = clone $this;
        $clone->default = $value;
        $clone->hasDefault = true;

        return $clone;
    }

    /** @param callable(mixed $value, mixed $originalValue): mixed $fn */
    public function transform(callable $fn): static
    {
        $clone = clone $this;
        $clone->transforms[] = $fn(...);

        return $clone;
    }

    /**
     * @param string|callable $message may use `${path}`, `${value}`, and any key of $params
     * @param callable(mixed $value, TestContext $ctx): (bool|YupError|list<YupError>) $test
     */
    public function test(string $name, string|callable $message, callable $test): static
    {
        $clone = clone $this;
        $clone->tests[] = ['name' => $name, 'message' => is_string($message) ? $message : \Closure::fromCallable($message), 'test' => $test(...)];

        return $clone;
    }

    /** @param list<mixed> $values */
    public function oneOf(array $values, string $message = '${path} must be one of the following values: ${values}'): static
    {
        $clone = clone $this;
        $clone->tests[] = [
            'name' => 'oneOf',
            'message' => self::interpolate($message, ['path' => '${path}', 'values' => implode(', ', array_map(static fn (mixed $v): string => is_null($v) ? 'null' : (is_scalar($v) ? (string) $v : json_encode($v)), $values))]),
            'test' => static function (mixed $v) use ($values): bool {
                if ($v === null || $v instanceof Undefined) {
                    return true;
                }
                foreach ($values as $allowed) {
                    if ($allowed === $v || (is_numeric($allowed) && is_numeric($v) && $allowed == $v && !is_string($v))) {
                        return true;
                    }
                }

                return false;
            },
        ];

        return $clone;
    }

    /** @param list<mixed> $values */
    public function equals(array $values, ?string $message = null): static
    {
        return $this->oneOf($values, $message ?? '${path} must be one of the following values: ${values}');
    }

    /** Merge another schema's tests into this one (`schema.concat(other)`). */
    public function concat(Yup $other): static
    {
        $clone = clone $this;
        $clone->tests = [...$clone->tests, ...$other->tests];
        $clone->transforms = [...$clone->transforms, ...$other->transforms];
        if ($other instanceof YupObject && $clone instanceof YupObject) {
            $clone->fields = [...$clone->fields, ...$other->fields];
        }
        if (!$other->optional) {
            $clone->optional = false;
        }
        if ($other->nullable) {
            $clone->nullable = true;
        }

        return $clone;
    }

    // --- validation ---------------------------------------------------------------------------

    /**
     * @param array{strict?: bool, abortEarly?: bool, path?: string} $options
     * @throws YupValidationError
     */
    public function validate(mixed $value, array $options = []): mixed
    {
        $errors = [];
        $result = $this->run($value, $options['path'] ?? '', (bool) ($options['strict'] ?? false), $errors, $value);

        if ($errors !== []) {
            throw new YupValidationError(array_map(static fn (array $e): array => [
                'path' => $e['path'] === '' ? [] : \Strapi\Utils\Primitives\Objects::toPath($e['path']),
                'message' => $e['message'],
                'name' => 'ValidationError',
                'value' => $e['value'],
            ], $errors));
        }

        return $result;
    }

    public function validateSync(mixed $value, array $options = []): mixed
    {
        return $this->validate($value, $options);
    }

    /** Resolve lazy schemas: the concrete schema for a value. */
    public function resolve(mixed $value): Yup
    {
        return $this;
    }

    /**
     * Cast (unless strict) then run tests. Errors are appended to $errors (abortEarly: false).
     *
     * @param list<array{path: string, message: string, value: mixed}> $errors
     */
    public function run(mixed $value, string $path, bool $strict, array &$errors, mixed $originalValue = null): mixed
    {
        $schema = $this->resolve($value);
        if ($schema !== $this) {
            return $schema->run($value, $path, $strict, $errors, $originalValue);
        }

        $strict = $strict || $this->strict;

        // default
        if ($value instanceof Undefined && $this->hasDefault) {
            $value = $this->default instanceof \Closure ? ($this->default)() : $this->default;
        }

        $original = $value;
        if (!$strict) {
            $value = $this->cast($value);
            foreach ($this->transforms as $transform) {
                $value = $transform($value, $original);
            }
        }

        // type check (skipped for undefined, and for null when nullable)
        if (!($value instanceof Undefined) && !($value === null && $this->nullable) && !$this->typeCheck($value)) {
            $errors[] = ['path' => $path, 'message' => $this->notTypeMessage($path, $value, $original), 'value' => $value];

            return $value;
        }

        // yup: `mixed()` accepts null whatever `nullable` says (its _typeCheck is always true); typed
        // schemas (string, number, array, object...) reject null through their own typeCheck above.
        $value = $this->runInner($value, $path, $strict, $errors);

        foreach ($this->tests as $test) {
            $ctx = new TestContext($path, $value, $original, $this);
            try {
                $result = ($test['test'])($value, $ctx);
            } catch (YupError $e) {
                $result = $e;
            }
            if ($result === true) {
                continue;
            }
            if ($result instanceof YupError) {
                $errors[] = ['path' => $result->path ?? $path, 'message' => $result->getMessage(), 'value' => $value];
                continue;
            }
            if (is_array($result)) {
                foreach ($result as $err) {
                    if ($err instanceof YupError) {
                        $errors[] = ['path' => $err->path ?? $path, 'message' => $err->getMessage(), 'value' => $value];
                    }
                }
                continue;
            }
            $message = $test['message'] instanceof \Closure ? ($test['message'])(['path' => $path, 'value' => $value]) : self::interpolate($test['message'], ['path' => $path === '' ? 'this' : $path, 'value' => $value]);
            $errors[] = ['path' => $path, 'message' => (string) $message, 'value' => $value];
        }

        return $value;
    }

    /** @param list<array{path: string, message: string, value: mixed}> $errors */
    protected function runInner(mixed $value, string $path, bool $strict, array &$errors): mixed
    {
        return $value;
    }

    protected function cast(mixed $value): mixed
    {
        return $value;
    }

    protected function typeCheck(mixed $value): bool
    {
        return true;
    }

    protected function isPresent(mixed $value): bool
    {
        return $value !== null && !($value instanceof Undefined);
    }

    protected function notTypeMessage(string $path, mixed $value, mixed $originalValue): string
    {
        $isCast = $originalValue !== null && !($originalValue instanceof Undefined) && $originalValue !== $value;
        $printed = PrintValue::printValue($value, true);
        $p = $path === '' ? 'this' : $path;

        return "{$p} must be a `{$this->type}` type, but the final value was: `{$printed}`" .
            ($isCast ? ' (cast from the value `' . PrintValue::printValue($originalValue, true) . '`).' : '.');
    }

    /** @param array<string, mixed> $params */
    public static function interpolate(string $message, array $params): string
    {
        return (string) preg_replace_callback('/\$\{(\w+)\}/', static function (array $m) use ($params): string {
            $v = $params[$m[1]] ?? '';
            if ($m[1] === 'path' && $v === '') {
                return 'this';
            }

            return is_scalar($v) ? (string) $v : (string) json_encode($v);
        }, $message);
    }

    public static function joinPath(string $parent, string|int $key): string
    {
        if (is_int($key) || ctype_digit((string) $key)) {
            return "{$parent}[{$key}]";
        }

        return $parent === '' ? (string) $key : "{$parent}.{$key}";
    }
}
