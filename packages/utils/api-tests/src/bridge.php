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
 * - a function of the test process is sent as `{"$callback": {"url", "id"}}` and arrives as a
 *   {@see Callback} (`strapi.db.lifecycles.subscribe({ afterCreate: jest.fn() })`). A chain ending
 *   with `{"keep": true}` keeps its value in the worker and answers `{"$handle": n}`; a chain that
 *   starts with `{"handle": n}` continues from that value (the unsubscribe function).
 * - an object with functions assigned to a property (`plugin.provider = { ...provider, uploadStream() {} }`)
 *   is an `{"assign": {"prop", "methods", "values"}}` step: the property becomes a {@see RemoteObject}
 *   until a `{"restore": prop}` step puts the original back.
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

    /** @var array<int, mixed> values kept by a `{"keep": true}` step */
    private array $handles = [];

    /** @var array<string, mixed> original values of properties replaced by an `assign` step */
    private array $assigned = [];

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
        } elseif (isset($steps[0]['handle'])) {
            $handle = (int) $steps[0]['handle'];
            if (!array_key_exists($handle, $this->handles)) {
                throw new \InvalidArgumentException("Unknown handle {$handle}");
            }
            $value = $this->handles[$handle];
            $i = 1;
        }

        for (; $i < $count; $i++) {
            $step = $steps[$i];
            $this->nullIsValue = false;
            if (array_key_exists('keep', $step)) {
                $this->handles[] = $value;

                return ['$handle' => array_key_last($this->handles)];
            }
            if (array_key_exists('assign', $step) || array_key_exists('restore', $step)) {
                return $this->toggleAssign($value, $step);
            }
            if (array_key_exists('spy', $step) || array_key_exists('unspy', $step)) {
                // `jest.spyOn(remote, method)` / `mockRestore()` (lib/bridge.js): last step of the chain
                return $this->toggleSpy($value, $step);
            }
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
     * Installs (`spy: { method, url, id }`) or removes (`unspy: method`) a jest mock on a service:
     * the service registered under the object's uid is replaced by a {@see Spy} wrapping it.
     *
     * @param array<string, mixed> $step
     */
    private function toggleSpy(mixed $target, array $step): bool
    {
        if (!is_object($target)) {
            throw new \InvalidArgumentException('Only methods of a service object can be spied on, got ' . get_debug_type($target));
        }

        $services = $this->strapi->get('services');
        $uid = null;
        foreach ($services->getAll() as $serviceUid => $service) {
            if ($service === $target) {
                $uid = (string) $serviceUid;
                break;
            }
        }
        if ($uid === null) {
            throw new \InvalidArgumentException('Only methods of a registered service can be spied on, got ' . get_debug_type($target));
        }

        if (isset($step['spy']) && is_array($step['spy'])) {
            $spy = $target instanceof Spy ? $target : new Spy($target);
            $spy->spy((string) $step['spy']['method'], (string) $step['spy']['url'], (int) $step['spy']['id']);
            $services->set($uid, $spy);

            return true;
        }

        if ($target instanceof Spy && !$target->unspy((string) $step['unspy'])) {
            $services->set($uid, $target->original);
        }

        return true;
    }

    /**
     * Replaces (`assign: { prop, methods, values }`) or restores (`restore: prop`) a property of an
     * object with an object of the test process (lib/bridge.js); last step of the chain.
     *
     * @param array<string, mixed> $step
     */
    private function toggleAssign(mixed $target, array $step): bool
    {
        if (!is_object($target)) {
            throw new \InvalidArgumentException('Only a property of an object can be assigned, got ' . get_debug_type($target));
        }

        if (isset($step['assign']) && is_array($step['assign'])) {
            $prop = (string) ($step['assign']['prop'] ?? '');
            $key = spl_object_id($target) . "\0" . $prop;
            $original = array_key_exists($key, $this->assigned) ? $this->assigned[$key] : $target->{$prop};
            $methods = [];
            foreach ((array) ($step['assign']['methods'] ?? []) as $name => $callback) {
                $resolved = $this->resolveRefs($callback);
                if ($resolved instanceof Callback) {
                    $methods[(string) $name] = $resolved;
                }
            }
            $values = [];
            foreach ((array) ($step['assign']['values'] ?? []) as $name => $v) {
                $values[(string) $name] = $this->resolveRefs($v);
            }
            $this->assigned[$key] = $original;
            $target->{$prop} = new RemoteObject($original, $methods, $values);

            return true;
        }

        $prop = (string) $step['restore'];
        $key = spl_object_id($target) . "\0" . $prop;
        if (array_key_exists($key, $this->assigned)) {
            $target->{$prop} = $this->assigned[$key];
            unset($this->assigned[$key]);
        }

        return true;
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
        if (array_keys($value) === ['$ref'] && is_array($value['$ref']) && array_is_list($value['$ref'])) {
            /** @var list<array<string, mixed>> $steps a chain recorded by lib/bridge.js */
            $steps = $value['$ref'];

            return $this->replay($steps);
        }
        if (array_keys($value) === ['$mcpTool'] && is_array($value['$mcpTool'])) {
            return McpDefinition::fromTest($this->resolveRefs($value['$mcpTool']));
        }
        if (array_keys($value) === ['$callback'] && is_array($value['$callback'])) {
            return new Callback((string) ($value['$callback']['url'] ?? ''), (int) ($value['$callback']['id'] ?? 0));
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
        if ($value instanceof \ArrayObject) {
            return self::export($value->getArrayCopy(), $depth + 1);
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
