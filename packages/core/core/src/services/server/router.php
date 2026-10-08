<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased as GroupDispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as RouteParser;

/**
 * Not an upstream file: stands in for `@koa/router`. Routes are collected with their Koa-style
 * path (`/articles/:id`, `/((?!uploads/).+)`, `/admin/:path*`) and compiled lazily into a
 * `nikic/fast-route` dispatcher. Path conversion:
 *   - `:name`  → `{name}`, `:name*` → `{name:.*}`, `:name?` → `[/{name}]`
 *   - a path containing `(` is treated as a raw regular expression (Koa/path-to-regexp style)
 *
 * `match()` returns the handler and params, `null` for 404, or a list of allowed methods for 405.
 *
 * @phpstan-type RouteEntry array{method: string, path: string, handler: callable, route: array<string, mixed>}
 */
final class Router
{
    /** @var list<RouteEntry> */
    private array $stack = [];

    /** @var list<array{regex: string, method: string, handler: callable, route: array<string, mixed>}> */
    private array $regexRoutes = [];

    private ?Dispatcher $dispatcher = null;

    public function __construct(private readonly string $prefix = '')
    {
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** @param array<string, mixed> $route */
    public function add(string $method, string $path, callable $handler, array $route = []): void
    {
        $fullPath = self::joinPath($this->prefix, $path);
        $method = strtoupper($method);
        $this->stack[] = ['method' => $method, 'path' => $fullPath, 'handler' => $handler, 'route' => $route];
        $this->dispatcher = null;
    }

    /** @return list<RouteEntry> */
    public function stack(): array
    {
        return $this->stack;
    }

    /** Mount another router's routes into this one (its prefix is already applied). */
    public function use(self $other): void
    {
        foreach ($other->stack as $entry) {
            $this->stack[] = $entry;
        }
        $this->dispatcher = null;
    }

    public static function joinPath(string $prefix, string $path): string
    {
        $prefix = rtrim($prefix, '/');
        $path = '/' . ltrim(trim($path), '/');
        $full = $prefix . $path;
        if ($full !== '/' && str_ends_with($full, '/') && !str_contains($full, '(')) {
            $full = rtrim($full, '/');
        }

        return $full === '' ? '/' : $full;
    }

    /** Convert a Koa path to FastRoute syntax. */
    public static function toFastRoute(string $path): string
    {
        // optional param `:name?` → `[/{name}]`
        $path = preg_replace('~/:([A-Za-z_][A-Za-z0-9_]*)\?~', '[/{$1}]', $path) ?? $path;
        // wildcard param `:name*` → `{name:.*}` (zero or more segments, including empty)
        $path = preg_replace_callback('~/:([A-Za-z_][A-Za-z0-9_]*)\*~', static fn (array $m): string => '[/{' . $m[1] . ':.*}]', $path) ?? $path;
        $path = preg_replace_callback('~/:([A-Za-z_][A-Za-z0-9_]*)\+~', static fn (array $m): string => '/{' . $m[1] . ':.+}', $path) ?? $path;
        // plain param
        $path = preg_replace('~:([A-Za-z_][A-Za-z0-9_]*)~', '{$1}', $path) ?? $path;

        return $path;
    }

    private function compile(): Dispatcher
    {
        $collector = new RouteCollector(new RouteParser(), new DataGenerator());
        $this->regexRoutes = [];
        $seen = [];

        foreach ($this->stack as $index => $entry) {
            $methods = $entry['method'] === 'ALL' ? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] : [$entry['method']];
            if (str_contains($entry['path'], '(')) {
                foreach ($methods as $method) {
                    $this->regexRoutes[] = ['regex' => '~^' . $entry['path'] . '$~', 'method' => $method, 'handler' => $entry['handler'], 'route' => $entry['route']];
                }
                continue;
            }

            $fastPath = self::toFastRoute($entry['path']);
            foreach ($methods as $method) {
                $key = $method . ' ' . $fastPath;
                if (isset($seen[$key])) {
                    continue; // first registration wins (Koa would run both; Strapi never relies on that)
                }
                $seen[$key] = true;
                try {
                    $collector->addRoute($method, $fastPath, $index);
                } catch (\FastRoute\BadRouteException $e) {
                    throw new \RuntimeException("Invalid route {$entry['method']} {$entry['path']}: {$e->getMessage()}", 0, $e);
                }
            }
        }

        $this->dispatcher = new GroupDispatcher($collector->getData());

        return $this->dispatcher;
    }

    /**
     * The matched route, or the list of methods the path accepts (405 material), or null (404).
     *
     * @return array{handler: callable, params: array<string, string>, route: array<string, mixed>}|list<string>|null
     */
    public function match(string $method, string $path): array|null
    {
        ['found' => $found, 'allowed' => $allowed] = $this->resolve($method, $path);

        return $found ?? ($allowed !== [] ? $allowed : null);
    }

    /**
     * `match()` with the two outcomes kept apart: `found` is the matched route (null when none),
     * `allowed` the methods the path accepts when it is known under other methods only.
     *
     * @return array{found: array{handler: callable, params: array<string, string>, route: array<string, mixed>}|null, allowed: list<string>}
     */
    public function resolve(string $method, string $path): array
    {
        $dispatcher = $this->dispatcher ?? $this->compile();
        $method = strtoupper($method);
        $lookup = $method === 'HEAD' ? 'GET' : $method;

        $result = $dispatcher->dispatch($lookup, rawurldecode($path));

        if ($result[0] === Dispatcher::FOUND) {
            $entry = $this->stack[$result[1]];
            $params = [];
            foreach ($result[2] as $name => $value) {
                $params[(string) $name] = (string) $value;
            }

            return ['found' => ['handler' => $entry['handler'], 'params' => $params, 'route' => $entry['route']], 'allowed' => []];
        }

        // regex routes (koa-static catch-all etc.) are checked after the declarative ones
        $allowed = $result[0] === Dispatcher::METHOD_NOT_ALLOWED ? $result[1] : [];
        foreach ($this->regexRoutes as $regexRoute) {
            if (preg_match($regexRoute['regex'], $path) !== 1) {
                continue;
            }
            if ($regexRoute['method'] === $lookup) {
                return ['found' => ['handler' => $regexRoute['handler'], 'params' => [], 'route' => $regexRoute['route']], 'allowed' => []];
            }
            $allowed[] = $regexRoute['method'];
        }

        return ['found' => null, 'allowed' => array_values(array_unique($allowed))];
    }
}
