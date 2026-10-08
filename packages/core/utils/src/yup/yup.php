<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/**
 * Not an upstream file: a synchronous port of `yup` 0.32.9 (the version pinned by @strapi/utils),
 * `BaseSchema` / `mixed()` here and one subclass per schema type in this directory. The
 * `@strapi/utils` extensions (yup.ts: `notNil`, `notNull`, `isFunction`, `isCamelCase`,
 * `isKebabCase`, `onlyContainsFunctions`, `uniqueProperty`, `strapiID()` and the `notType`
 * locale message) are built in. {@see \Strapi\Utils\Yup} is the `yup` facade.
 *
 * JS `undefined` (a missing key) is {@see Undefined::value()}; JS objects are associative arrays
 * (`[]` is accepted both as an empty object and as an empty array); JS functions are closures.
 *
 * Validation: `$schema->validate($value, ['strict' => false, 'abortEarly' => false])` returns the
 * cast value or throws a {@see YupError} (yup's `ValidationError`: `errors`, `inner`, `path`,
 * `value`, `type`). Test functions are called as `fn (mixed $value, TestContext $ctx)` where `$ctx`
 * is yup's `this` (`path`, `parent`, `options`, `createError()`, `resolve()`...); they return
 * true (pass), false (fail with the test's message), or a {@see YupError}, or throw one.
 *
 * @phpstan-type Spec array{strip: bool, strict: bool, abortEarly: bool, recursive: bool, nullable: bool, presence: string, label?: string|null, meta?: array<string, mixed>, default?: mixed, noUnknown?: bool}
 * @phpstan-type Options array<string, mixed>
 */
class Yup
{
    protected string $type = 'mixed';

    /** @var Spec */
    protected array $spec = [
        'strip' => false,
        'strict' => false,
        'abortEarly' => true,
        'recursive' => true,
        'nullable' => false,
        'presence' => 'optional',
    ];

    /** @var list<string> sibling keys this schema depends on (`when()`) */
    public array $deps = [];

    /** @var list<Condition> */
    protected array $conditions = [];

    protected ReferenceSet $whitelist;

    protected ReferenceSet $blacklist;

    protected ?TestConfig $typeError = null;

    protected ?TestConfig $whitelistError = null;

    protected ?TestConfig $blacklistError = null;

    /** @var array<string, bool> */
    protected array $exclusiveTests = [];

    /** @var list<TestConfig> */
    protected array $tests = [];

    /** @var list<\Closure> user transforms `fn (mixed $value, mixed $originalValue, Yup $schema): mixed` */
    protected array $transforms = [];

    public function __construct()
    {
        $this->whitelist = new ReferenceSet();
        $this->blacklist = new ReferenceSet();
        $this->typeError = self::makeTypeError(Locale::notType(...));
    }

    public function __clone()
    {
        $this->whitelist = clone $this->whitelist;
        $this->blacklist = clone $this->blacklist;
    }

    // --- factories (kept here for the code that used this class before the facade existed) -----

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

    public static function array(?Yup $of = null): YupArray
    {
        return new YupArray($of);
    }

    /** @param array<string, Yup|null> $shape */
    public static function object(array $shape = []): YupObject
    {
        return new YupObject($shape);
    }

    /** @param callable(mixed, array<string, mixed>): Yup $builder */
    public static function lazy(callable $builder): YupLazy
    {
        return new YupLazy($builder);
    }

    public static function strapiID(): StrapiIdSchema
    {
        return new StrapiIdSchema();
    }

    // --- introspection ---------------------------------------------------------------------------

    public function type(): string
    {
        return $this->type;
    }

    /** @return Spec */
    public function spec(): array
    {
        return $this->spec;
    }

    /** @return list<TestConfig> */
    public function tests(): array
    {
        return $this->tests;
    }

    protected function typeCheck(mixed $value): bool
    {
        return true;
    }

    public function isType(mixed $value): bool
    {
        if ($this->spec['nullable'] && $value === null) {
            return true;
        }

        return $this->typeCheck($value);
    }

    // --- modifiers -------------------------------------------------------------------------------

    public function label(string $label): static
    {
        $next = clone $this;
        $next->spec['label'] = $label;

        return $next;
    }

    /** @param array<string, mixed> $meta */
    public function meta(array $meta): static
    {
        $next = clone $this;
        $next->spec['meta'] = [...($next->spec['meta'] ?? []), ...$meta];

        return $next;
    }

    /**
     * `schema.concat(other)`: a copy of `$schema` with this schema's tests run first, as yup does
     * (the other schema's spec — nullable, presence, strict, default... — wins).
     */
    public function concat(?Yup $schema): Yup
    {
        if ($schema === null || $schema === $this) {
            return $this;
        }
        if ($schema->type !== $this->type && $this->type !== 'mixed') {
            throw new \TypeError("You cannot `concat()` schema's of different types: {$this->type} and {$schema->type}");
        }

        $combined = clone $schema;
        $combined->spec = [...$this->spec, ...$combined->spec];
        $combined->typeError ??= $this->typeError;
        $combined->whitelistError ??= $this->whitelistError;
        $combined->blacklistError ??= $this->blacklistError;
        $combined->whitelist = $this->whitelist->merge($schema->whitelist, $schema->blacklist);
        $combined->blacklist = $this->blacklist->merge($schema->blacklist, $schema->whitelist);
        $combined->tests = $this->tests;
        $combined->exclusiveTests = $this->exclusiveTests;
        foreach ($schema->tests as $test) {
            $combined->addTest($test);
        }

        return $combined;
    }

    public function strict(bool $isStrict = true): static
    {
        $next = clone $this;
        $next->spec['strict'] = $isStrict;

        return $next;
    }

    public function strip(bool $strip = true): static
    {
        $next = clone $this;
        $next->spec['strip'] = $strip;

        return $next;
    }

    /** `schema.default(value)`; a closure is called each time the default is needed. */
    public function default(mixed $default): static
    {
        $next = clone $this;
        $next->spec['default'] = $default;

        return $next;
    }

    /**
     * `schema.default()` with no argument in yup.
     *
     * @param Options $options
     */
    public function getDefault(array $options = []): mixed
    {
        return $this->resolve($options)->computeDefault();
    }

    protected function computeDefault(): mixed
    {
        if (!array_key_exists('default', $this->spec)) {
            return Undefined::value();
        }
        $default = $this->spec['default'];
        if ($default === null) {
            return null;
        }

        return $default instanceof \Closure ? $default() : $default;
    }

    public function hasDefault(): bool
    {
        return array_key_exists('default', $this->spec);
    }

    public function nullable(bool $isNullable = true): static
    {
        $next = clone $this;
        $next->spec['nullable'] = $isNullable;

        return $next;
    }

    public function defined(string|\Closure $message = Locale::MIXED_DEFINED): static
    {
        return $this->test([
            'name' => 'defined',
            'message' => $message,
            'exclusive' => true,
            'test' => static fn (mixed $value): bool => !($value instanceof Undefined),
        ]);
    }

    public function required(string|\Closure $message = Locale::MIXED_REQUIRED): static
    {
        $next = clone $this;
        $next->spec['presence'] = 'required';
        $next->addTest(new TestConfig(
            name: 'required',
            message: $message,
            test: static fn (mixed $value, TestContext $ctx): bool => $ctx->schema->isPresent($value),
            exclusive: true,
        ));

        return $next;
    }

    public function notRequired(): static
    {
        $next = clone $this;
        $next->spec['presence'] = 'optional';
        $next->tests = array_values(array_filter($next->tests, static fn (TestConfig $t): bool => $t->name !== 'required'));

        return $next;
    }

    /** yup 0.32 alias of `notRequired()`. */
    public function optional(): static
    {
        return $this->notRequired();
    }

    public function isPresent(mixed $value): bool
    {
        return $value !== null && !($value instanceof Undefined);
    }

    /** @param callable(mixed $value, mixed $originalValue, Yup $schema): mixed $fn */
    public function transform(callable $fn): static
    {
        $next = clone $this;
        $next->transforms[] = $fn(...);

        return $next;
    }

    /**
     * `test(name, message, fn)`, `test(name, fn)`, `test(fn)` or `test(['name' => ..., 'message' => ...,
     * 'test' => fn, 'exclusive' => bool, 'params' => [...]])`.
     *
     * An exclusive test replaces the tests of the same name; a non-exclusive one is stacked (unless
     * the very same function was already added under that name).
     *
     * @param string|array<string, mixed>|callable $nameOrOptions
     */
    public function test(string|array|callable $nameOrOptions, string|callable|null $message = null, ?callable $test = null): static
    {
        $argc = func_num_args();
        if (is_array($nameOrOptions)) {
            $opts = $nameOrOptions;
        } elseif (!is_string($nameOrOptions)) {
            $opts = ['test' => $nameOrOptions];
        } elseif ($argc === 2) {
            $opts = ['name' => $nameOrOptions, 'test' => $message];
        } else {
            $opts = ['name' => $nameOrOptions, 'message' => $message, 'test' => $test];
        }

        if (!isset($opts['test']) || !is_callable($opts['test'])) {
            throw new \TypeError('`test` is a required parameters');
        }
        $msg = $opts['message'] ?? Locale::MIXED_DEFAULT;

        $next = clone $this;
        $next->addTest(new TestConfig(
            name: isset($opts['name']) ? (string) $opts['name'] : null,
            message: is_string($msg) ? $msg : (is_callable($msg) ? $msg(...) : Locale::MIXED_DEFAULT),
            test: $opts['test'](...),
            exclusive: (bool) ($opts['exclusive'] ?? false),
            params: is_array($opts['params'] ?? null) ? $opts['params'] : [],
        ));

        return $next;
    }

    /** In-place `test()` (yup's `withMutation`). */
    protected function addTest(TestConfig $config): void
    {
        $isExclusive = $config->exclusive || ($config->name !== null && ($this->exclusiveTests[$config->name] ?? false) === true);
        if ($config->exclusive && $config->name === null) {
            throw new \TypeError('Exclusive tests must provide a unique `name` identifying the test');
        }
        if ($config->name !== null) {
            $this->exclusiveTests[$config->name] = $config->exclusive;
        }

        $this->tests = array_values(array_filter($this->tests, static function (TestConfig $t) use ($config, $isExclusive): bool {
            if ($t->name === $config->name) {
                if ($isExclusive) {
                    return false;
                }
                if ($t->test === $config->test) {
                    return false;
                }
            }

            return true;
        }));
        $this->tests[] = $config;
    }

    /**
     * `when(keys, { is, then, otherwise })` or `when(keys, fn (...values, schema, options) => schema)`.
     * Keys are sibling names, `$context` keys or `.` (the value itself); `when(options)` alone uses `.`.
     *
     * @param string|list<string>|array<string, mixed>|\Closure $keys
     * @param array<string, mixed>|\Closure|null $options
     */
    public function when(string|array|\Closure $keys, array|\Closure|null $options = null): static
    {
        if ($options === null || (is_array($keys) && !array_is_list($keys)) || $keys instanceof \Closure) {
            $options = $keys;
            $keys = '.';
        }
        /** @var array<string, mixed>|\Closure $options */
        $next = clone $this;
        $refs = array_map(static fn (string $key): Reference => new Reference($key), is_array($keys) ? array_values(array_map('strval', $keys)) : [$keys]);
        foreach ($refs as $ref) {
            if ($ref->isSibling) {
                $next->deps[] = $ref->key;
            }
        }
        $next->conditions[] = new Condition($refs, $options);

        return $next;
    }

    public function typeError(string|\Closure $message): static
    {
        $next = clone $this;
        $next->typeError = self::makeTypeError($message);

        return $next;
    }

    private static function makeTypeError(string|\Closure $message): TestConfig
    {
        return new TestConfig(
            name: 'typeError',
            message: $message,
            test: static function (mixed $value, TestContext $ctx): bool|YupError {
                if (!($value instanceof Undefined) && !$ctx->schema->isType($value)) {
                    return $ctx->createError(['params' => ['type' => $ctx->schema->type]]);
                }

                return true;
            },
        );
    }

    /** @param list<mixed> $enums */
    public function oneOf(array $enums, string|\Closure $message = Locale::MIXED_ONE_OF): static
    {
        $next = clone $this;
        foreach ($enums as $value) {
            $next->whitelist->add($value);
            $next->blacklist->delete($value);
        }
        $next->whitelistError = new TestConfig(
            name: 'oneOf',
            message: $message,
            test: static function (mixed $value, TestContext $ctx): bool|YupError {
                if ($value instanceof Undefined) {
                    return true;
                }
                $valids = $ctx->schema->whitelist;

                return $valids->has($value, $ctx->resolve(...))
                    ? true
                    : $ctx->createError(['params' => ['values' => $valids->join()]]);
            },
        );

        return $next;
    }

    /** @param list<mixed> $enums */
    public function equals(array $enums, string|\Closure $message = Locale::MIXED_ONE_OF): static
    {
        return $this->oneOf($enums, $message);
    }

    /** @param list<mixed> $enums */
    public function is(array $enums, string|\Closure $message = Locale::MIXED_ONE_OF): static
    {
        return $this->oneOf($enums, $message);
    }

    /** @param list<mixed> $enums */
    public function notOneOf(array $enums, string|\Closure $message = Locale::MIXED_NOT_ONE_OF): static
    {
        $next = clone $this;
        foreach ($enums as $value) {
            $next->blacklist->add($value);
            $next->whitelist->delete($value);
        }
        $next->blacklistError = new TestConfig(
            name: 'notOneOf',
            message: $message,
            test: static function (mixed $value, TestContext $ctx): bool|YupError {
                $invalids = $ctx->schema->blacklist;
                if ($invalids->has($value, $ctx->resolve(...))) {
                    return $ctx->createError(['params' => ['values' => $invalids->join()]]);
                }

                return true;
            },
        );

        return $next;
    }

    /** @param list<mixed> $enums */
    public function not(array $enums, string|\Closure $message = Locale::MIXED_NOT_ONE_OF): static
    {
        return $this->notOneOf($enums, $message);
    }

    /** @param list<mixed> $enums */
    public function nope(array $enums, string|\Closure $message = Locale::MIXED_NOT_ONE_OF): static
    {
        return $this->notOneOf($enums, $message);
    }

    // --- @strapi/utils yup.ts extensions ------------------------------------------------------------

    private static ?\Closure $isNotNilTest = null;

    private static ?\Closure $isNotNullTest = null;

    /** yup.ts: `.notNil()` — neither null nor undefined. */
    public function notNil(string|\Closure $message = '${path} must be defined.'): static
    {
        self::$isNotNilTest ??= static fn (mixed $value): bool => $value !== null && !($value instanceof Undefined);

        return $this->test('defined', $message, self::$isNotNilTest);
    }

    /** yup.ts: `.notNull()` */
    public function notNull(string|\Closure $message = '${path} cannot be null.'): static
    {
        self::$isNotNullTest ??= static fn (mixed $value): bool => $value !== null;

        return $this->test('defined', $message, self::$isNotNullTest);
    }

    /** yup.ts: `.isFunction()` — undefined or a closure/invokable. */
    public function isFunction(string|\Closure $message = '${path} is not a function'): static
    {
        return $this->test('is a function', $message, static fn (mixed $value): bool => $value instanceof Undefined || self::isJsFunction($value));
    }

    public static function isJsFunction(mixed $value): bool
    {
        return $value instanceof \Closure || (is_object($value) && is_callable($value));
    }

    // --- casting ---------------------------------------------------------------------------------

    /** @param Options $options */
    public function resolve(array $options = []): Yup
    {
        $schema = $this;
        if ($schema->conditions !== []) {
            $conditions = $schema->conditions;
            $schema = clone $schema;
            $schema->conditions = [];
            foreach ($conditions as $condition) {
                $schema = $condition->resolve($schema, $options);
            }
            $schema = $schema->resolve($options);
        }

        return $schema;
    }

    /**
     * `schema.cast(value)`: throws a \TypeError when the result does not satisfy the type, unless
     * `assert: false`.
     *
     * @param Options $options
     */
    public function cast(mixed $value, array $options = []): mixed
    {
        $resolved = $this->resolve([...$options, 'value' => $value]);
        $result = $resolved->castValue($value, $options);

        if (!($value instanceof Undefined) && ($options['assert'] ?? true) !== false && $resolved->isType($result) !== true) {
            $formattedValue = self::print($value);
            $formattedResult = self::print($result);
            $path = isset($options['path']) && $options['path'] !== '' ? $options['path'] : 'field';

            throw new \TypeError(
                "The value of {$path} could not be cast to a value that satisfies the schema type: \"{$resolved->type}\". \n\n"
                . "attempted value: {$formattedValue} \n"
                . ($formattedResult !== $formattedValue ? "result of cast: {$formattedResult}" : ''),
            );
        }

        return $result;
    }

    /**
     * yup's `_cast`: the type's own transform, then user transforms, then the default for undefined.
     *
     * @param Options $options
     */
    protected function castValue(mixed $rawValue, array $options): mixed
    {
        $value = $rawValue;
        if (!($rawValue instanceof Undefined)) {
            $value = $this->typeTransform($value);
            foreach ($this->transforms as $fn) {
                $value = $fn($value, $rawValue, $this);
            }
        }
        if ($value instanceof Undefined) {
            $value = $this->getDefault();
        }

        return $value;
    }

    /** The transform each yup type registers in its constructor. */
    protected function typeTransform(mixed $value): mixed
    {
        return $value;
    }

    // --- validation ------------------------------------------------------------------------------

    /**
     * Options: `strict`, `abortEarly`, `recursive`, `stripUnknown`, `context`, `path`, `parent`.
     *
     * @param Options $options
     * @throws YupError
     */
    public function validate(mixed $value, array $options = []): mixed
    {
        $schema = $this->resolve([...$options, 'value' => $value]);
        [$err, $result] = $schema->runValidation($value, $options);
        if ($err !== null) {
            throw $err;
        }

        return $result;
    }

    /**
     * @param Options $options
     * @throws YupError
     */
    public function validateSync(mixed $value, array $options = []): mixed
    {
        return $this->validate($value, $options);
    }

    /** @param Options $options */
    public function isValid(mixed $value, array $options = []): bool
    {
        try {
            $this->validate($value, $options);

            return true;
        } catch (YupError) {
            return false;
        }
    }

    /** @param Options $options */
    public function isValidSync(mixed $value, array $options = []): bool
    {
        return $this->isValid($value, $options);
    }

    /**
     * Resolve, then validate: the callback form of yup's `validate(value, options, cb)` used for
     * nested validations — returns `[error|null, value]` instead of throwing.
     *
     * @param Options $options
     * @return array{0: YupError|null, 1: mixed}
     */
    public function validateNested(mixed $value, array $options): array
    {
        return $this->resolve([...$options, 'value' => $value])->runValidation($value, $options);
    }

    /**
     * yup's `_validate`.
     *
     * @param Options $options
     * @return array{0: YupError|null, 1: mixed}
     */
    protected function runValidation(mixed $value, array $options): array
    {
        $path = isset($options['path']) && is_string($options['path']) ? $options['path'] : null;
        $originalValue = array_key_exists('originalValue', $options) && !($options['originalValue'] instanceof Undefined) ? $options['originalValue'] : $value;
        $strict = (bool) ($options['strict'] ?? $this->spec['strict']);
        $abortEarly = (bool) ($options['abortEarly'] ?? $this->spec['abortEarly']);

        if (!$strict) {
            $value = $this->castValue($value, [...$options, 'assert' => false]);
        }

        $args = [
            'value' => $value,
            'path' => $path,
            'options' => $options,
            'originalValue' => $originalValue,
            'schema' => $this,
            'label' => $this->spec['label'] ?? null,
            'from' => $options['from'] ?? [],
        ];

        $initialTests = array_values(array_filter([$this->typeError, $this->whitelistError, $this->blacklistError]));
        $err = self::runTests(array_map(fn (TestConfig $t): \Closure => fn (): ?YupError => $this->runTest($t, $args), $initialTests), $value, $path, $abortEarly);
        if ($err !== null) {
            return [$err, $value];
        }

        $err = self::runTests(array_map(fn (TestConfig $t): \Closure => fn (): ?YupError => $this->runTest($t, $args), $this->tests), $value, $path, $abortEarly);

        return [$err, $value];
    }

    /**
     * yup's `runTests`: runs every test (or stops at the first error with `endEarly`), sorts nested
     * errors, puts `$errors` (the parent's own errors) after them and aggregates into one error.
     *
     * @param list<\Closure(): ?YupError> $tests
     * @param list<YupError> $errors
     * @param (\Closure(YupError, YupError): int)|null $sort
     */
    protected static function runTests(array $tests, mixed $value, ?string $path, bool $endEarly, array $errors = [], ?\Closure $sort = null): ?YupError
    {
        $nested = [];
        foreach ($tests as $test) {
            $err = $test();
            if ($err === null) {
                continue;
            }
            if ($endEarly) {
                $err->value = $value;

                return $err;
            }
            $nested[] = $err;
        }

        if ($nested !== []) {
            if ($sort !== null) {
                usort($nested, $sort);
            }
            $errors = [...$nested, ...$errors];
        }

        return $errors === [] ? null : new YupError($errors, $value, $path);
    }

    /**
     * yup's `createValidation()(args)`: run one test, turning a falsy result into the test's error.
     *
     * @param array{value: mixed, path: string|null, options: Options, originalValue: mixed, schema: Yup, label: string|null, from: mixed} $args
     */
    protected function runTest(TestConfig $config, array $args): ?YupError
    {
        $options = $args['options'];
        $ctx = new TestContext(
            path: $args['path'] ?? '',
            parent: array_key_exists('parent', $options) ? $options['parent'] : Undefined::value(),
            type: $config->name,
            options: $options,
            originalValue: $args['originalValue'],
            value: $args['value'],
            schema: $args['schema'],
            label: $args['label'],
            from: $args['from'],
            config: $config,
        );

        try {
            $result = ($config->test)($args['value'], $ctx);
        } catch (YupError $e) {
            return $e;
        }

        if ($result instanceof YupError) {
            return $result;
        }
        // PHP convenience kept from the earlier subset: a list of errors
        if (is_array($result) && $result !== [] && array_is_list($result) && array_filter($result, static fn (mixed $e): bool => !($e instanceof YupError)) === []) {
            /** @var list<YupError> $result */
            return new YupError($result, $args['value'], $args['path']);
        }
        if (!self::truthy($result)) {
            return $ctx->createError();
        }

        return null;
    }

    // --- describe ------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $seen = [];
        $tests = [];
        foreach ($this->tests as $t) {
            if (in_array($t->name, $seen, true)) {
                continue;
            }
            $seen[] = $t->name;
            $tests[] = ['name' => $t->name, 'params' => $t->params === [] ? null : $t->params];
        }

        return [
            'meta' => $this->spec['meta'] ?? null,
            'label' => $this->spec['label'] ?? null,
            'type' => $this->type,
            'oneOf' => $this->whitelist->describe(),
            'notOneOf' => $this->blacklist->describe(),
            'tests' => $tests,
        ];
    }

    // --- helpers -------------------------------------------------------------------------------

    /** yup's `isAbsent` (`value == null`). */
    public static function isAbsent(mixed $value): bool
    {
        return $value === null || $value instanceof Undefined;
    }

    /** JS truthiness. */
    public static function truthy(mixed $value): bool
    {
        if ($value instanceof Undefined || $value === null || $value === false || $value === '' || $value === 0) {
            return false;
        }
        if (is_float($value)) {
            return $value !== 0.0 && !is_nan($value);
        }

        return true;
    }

    /**
     * yup's `printValue` (util/printValue.js): simple values printed as JS would, anything else as
     * `JSON.stringify(value, replacer, 2)` where the replacer prints every simple leaf.
     */
    public static function print(mixed $value, bool $quoteStrings = false): string
    {
        $simple = self::printSimpleValue($value, $quoteStrings);
        if ($simple !== null) {
            return $simple;
        }

        $json = (string) json_encode(self::printReplace($value, $quoteStrings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        // JSON_PRETTY_PRINT indents by 4 spaces, JSON.stringify(_, _, 2) by 2
        return (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
    }

    private static function printSimpleValue(mixed $value, bool $quoteStrings): ?string
    {
        return match (true) {
            $value instanceof Undefined => 'undefined',
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) && $value === 0.0 && fdiv(1, $value) < 0 => '-0',
            is_int($value), is_float($value) => self::jsNumberToString($value),
            is_string($value) => $quoteStrings ? "\"{$value}\"" : $value,
            $value instanceof \Closure => '[Function anonymous]',
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z'),
            $value instanceof \Throwable => '[' . (new \ReflectionClass($value))->getShortName() . ': ' . $value->getMessage() . ']',
            $value instanceof Reference => (string) $value,
            default => null,
        };
    }

    private static function printReplace(mixed $value, bool $quoteStrings): mixed
    {
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);

            return $vars === [] ? new \stdClass() : self::printReplace($vars, $quoteStrings);
        }
        if (is_object($value)) {
            return $value instanceof \JsonSerializable ? self::printReplace($value->jsonSerialize(), $quoteStrings) : new \stdClass();
        }
        if (!is_array($value)) {
            return self::printSimpleValue($value, $quoteStrings) ?? $value;
        }

        return array_map(static fn (mixed $v): mixed => self::printSimpleValue($v, $quoteStrings) ?? self::printReplace($v, $quoteStrings), $value);
    }

    /** JS `String(value)`. */
    public static function jsString(mixed $value): string
    {
        return match (true) {
            $value instanceof Undefined => 'undefined',
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => self::jsNumberToString($value),
            is_string($value) => $value,
            $value instanceof Reference => (string) $value,
            is_array($value) && array_is_list($value) => implode(',', array_map(static fn (mixed $v): string => $v === null || $v instanceof Undefined ? '' : self::jsString($v), $value)),
            $value instanceof \Stringable => (string) $value,
            default => '[object Object]',
        };
    }

    public static function jsNumberToString(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }
        if ($value === 0.0) {
            return '0';
        }
        if (floor($value) === $value && abs($value) < 1e21) {
            return number_format($value, 0, '', '');
        }
        $json = (string) json_encode($value);
        if (preg_match('/^(-?\d+(?:\.\d+)?)[eE]([+-]?)(\d+)$/', $json, $m) === 1) {
            $mantissa = str_contains($m[1], '.') ? rtrim(rtrim($m[1], '0'), '.') : $m[1];

            return $mantissa . 'e' . ($m[2] === '-' ? '-' : '+') . $m[3];
        }

        return $json;
    }

    /** JS `===` for the values yup compares (one number type: `1 === 1.0`; NaN is never equal). */
    public static function sameValue(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }
}
