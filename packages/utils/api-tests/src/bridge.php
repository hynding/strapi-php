<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

use Strapi\Core\Strapi;
use Strapi\Database\Database;
use Strapi\Database\Query\SqlBuilder;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Lets upstream's Jest API suite drive a live PHP instance.
 *
 * Upstream tests run Strapi in-process and reach into it directly:
 * `await strapi.db.query('admin::user').findOne({ where })`, `strapi.config.set(...)`,
 * `strapi.sessionManager('admin').generateRefreshToken(...)`. The JS side
 * (tests/api/lib/bridge.js) records such an expression as a chain of steps and posts it here;
 * the chain is replayed on the worker's Strapi instance, so later HTTP requests see its effects.
 *
 * A step is `{"get": name}` or `{"call": [args...]}`. Upstream's JS API and this port share
 * names, so replay maps them mechanically:
 *
 * - `get` then `call` on an object: call the method of that name. When the method takes no
 *   parameters but arguments are given and it returns a callable, the result is invoked with
 *   them (`strapi.sessionManager('admin')` → `$strapi->sessionManager()('admin')`).
 * - `get` alone: a method without required parameters is called (`strapi.db`, `strapi.config`);
 *   otherwise a public property, `__get`, an array key or an `ArrayAccess` offset is read.
 * - an argument `{"$ref": [steps]}` is resolved as another chain first, so
 *   `sanitize.output(data, strapi.getModel(uid))` works without awaiting the model.
 * - a chain may start with `{"class": "Fully\\Qualified"}` to reach a class upstream's helpers
 *   `require()` directly (api-tests/models.js uses document-service/components): static methods
 *   are called statically, instance methods on `new Class($strapi)`.
 *
 * - `strapi.db.getConnection()` (a knex instance upstream) is the database's knex-like
 *   {@see SqlBuilder} (`$db->sql()`), so `getConnection().from(t).where(...).update(...)` replays;
 *   awaiting a builder runs it. `strapi.db.connection(table)` (knex called with a table name) is
 *   that builder `from(table)`, wrapped in {@see KnexQuery} for knex's `select(a, b)`/`first()`.
 *
 * Only enabled when the worker script mounts it (tests/api/app/public/index.php); never in an app.
 */
final class Bridge
{
    public const string PATH = '/__api-tests/rpc';

    /** Whether the last replayed call returns a nullable type, so its `null` is a value (JS `null`, not `undefined`). */
    private bool $nullIsValue = false;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function lastCallReturnsNullable(): bool
    {
        return $this->nullIsValue;
    }

    /**
     * @param array{steps?: list<array<string, mixed>>} $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(array $payload): array
    {
        try {
            $this->nullIsValue = false;
            $value = $this->replay($payload['steps'] ?? []);

            // a knex query builder is a thenable: awaiting it runs the query
            if ($value instanceof SqlBuilder || $value instanceof KnexQuery) {
                $value = $value->run();
            }

            // `null` from a method declared `?Type` (findOne...) is JS `null`; any other null is `undefined`
            return ['status' => 200, 'body' => ['result' => self::export($value), ...($value === null && $this->lastCallReturnsNullable() ? ['null' => true] : [])]];
        } catch (\Throwable $e) {
            $error = [
                'name' => $e instanceof ApplicationError ? $e->name : (new \ReflectionClass($e))->getShortName(),
                'message' => $e->getMessage(),
                'class' => $e::class,
                'at' => $e->getFile() . ':' . $e->getLine(),
            ];
            if ($e instanceof ApplicationError) {
                $error['details'] = $e->details;
                $error['status'] = $e->status;
            }

            return ['status' => 500, 'body' => ['error' => $error]];
        }
    }

    /** @param list<array<string, mixed>> $steps */
    public function replay(array $steps): mixed
    {
        $value = $this->strapi;
        $count = count($steps);
        $i = 0;

        if (isset($steps[0]['class'])) {
            $class = (string) $steps[0]['class'];
            if (!class_exists($class)) {
                throw new \InvalidArgumentException("Unknown class {$class}");
            }
            $value = new ClassRef($class, $this->strapi);
            $i = 1;
        }

        for (; $i < $count; $i++) {
            $step = $steps[$i];
            $this->nullIsValue = false;
            if (!array_key_exists('get', $step)) {
                // a bare call: the current value is a callable
                $value = self::invoke($value, $this->args($step['call'] ?? []));
                continue;
            }

            $name = (string) $step['get'];
            $next = $steps[$i + 1] ?? null;
            if (is_array($next) && array_key_exists('call', $next) && !array_key_exists('get', $next)) {
                $value = $this->callMember($value, $name, $this->args($next['call']));
                $i++;
                continue;
            }

            $value = self::read($value, $name);
        }

        return $value;
    }

    /**
     * @param list<mixed> $args
     * @return list<mixed>
     */
    private function args(array $args): array
    {
        return array_map(fn (mixed $arg): mixed => $this->resolveRefs($arg), $args);
    }

    private function resolveRefs(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) === ['$ref'] && is_array($value['$ref'])) {
            return $this->replay($value['$ref']);
        }

        return array_map(fn (mixed $v): mixed => $this->resolveRefs($v), $value);
    }

    /** @param list<mixed> $args */
    private function callMember(mixed $target, string $name, array $args): mixed
    {
        if ($target instanceof ClassRef) {
            return $target->call($name, $args);
        }

        if ($target instanceof Database && $name === 'getConnection' && $args === []) {
            return $target->sql();
        }

        // `strapi.db.connection(table)`: knex called with a table name
        if ($target instanceof Database && $name === 'connection' && count($args) === 1 && is_string($args[0])) {
            return new KnexQuery($target->sql()->from($args[0]));
        }

        if (is_object($target) && method_exists($target, $name)) {
            $method = new \ReflectionMethod($target, $name);
            $returnType = $method->getReturnType();
            $this->nullIsValue = $returnType instanceof \ReflectionNamedType && $returnType->allowsNull() && !in_array($returnType->getName(), ['mixed', 'null', 'void'], true);
            if ($method->isPublic()) {
                if ($args !== [] && $method->getNumberOfParameters() === 0) {
                    $result = $target->{$name}();
                    if (is_callable($result)) {
                        return $result(...$args);
                    }

                    return $result;
                }

                return $target->{$name}(...$args);
            }
        }
        if (is_object($target) && method_exists($target, '__call')) {
            return $target->{$name}(...$args);
        }

        // a callable stored under that name (action maps, service arrays)
        return self::invoke(self::read($target, $name), $args);
    }

    /** @param list<mixed> $args */
    private static function invoke(mixed $callable, array $args): mixed
    {
        if (!is_callable($callable)) {
            throw new \BadMethodCallException('Not callable: ' . get_debug_type($callable));
        }

        return $callable(...$args);
    }

    private static function read(mixed $target, string $name): mixed
    {
        if (is_array($target)) {
            if (!array_key_exists($name, $target)) {
                return null; // JS: reading a missing key is undefined
            }

            return $target[$name];
        }
        if ($target instanceof \ArrayAccess && $target->offsetExists($name)) {
            return $target[$name];
        }
        if (is_object($target)) {
            if (method_exists($target, $name)) {
                $method = new \ReflectionMethod($target, $name);
                if ($method->isPublic() && $method->getNumberOfRequiredParameters() === 0) {
                    return $target->{$name}();
                }
                // JS reads the property (`strapi.db.metadata.get(uid)`): a public property of the
                // same name wins over a method that needs arguments
                if (property_exists($target, $name) && (new \ReflectionProperty($target, $name))->isPublic()) {
                    return $target->{$name};
                }
                // a method that needs arguments, read without calling: hand back a callable
                return $target->{$name}(...);
            }
            if (property_exists($target, $name) || isset($target->{$name})) {
                return $target->{$name};
            }
        }

        throw new \OutOfBoundsException(sprintf('Cannot read "%s" of %s', $name, get_debug_type($target)));
    }

    /** JSON-safe copy of a result: objects become arrays the way their JSON form would. */
    public static function export(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 64) {
            return null;
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::export($v, $depth + 1), $value);
        }
        if ($value instanceof \JsonSerializable) {
            return self::export($value->jsonSerialize(), $depth + 1);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.v\Z');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \Closure || $value instanceof Strapi) {
            return null;
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            return self::export($value->toArray(), $depth + 1);
        }
        if (is_object($value)) {
            return self::export(get_object_vars($value), $depth + 1);
        }

        return null;
    }
}
