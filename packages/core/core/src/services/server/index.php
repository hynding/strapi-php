<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context as ContextContract;

/**
 * Port of packages/core/core/src/services/server/index.ts (`createServer`): `strapi.server`.
 *
 * Koa is replaced by a PSR-7 pipeline: `handle(ServerRequestInterface): ResponseInterface` runs
 * the global middlewares (`use()`), then the router (health check, `content-api` and `admin` APIs,
 * plus routes added with `routes()`), and converts the {@see Context} to a response.
 * `listen()` serves with PHP's built-in web server (see {@see HttpServer}).
 */
final class Server
{
    /** @var list<callable> global middlewares */
    private array $middlewares = [];

    public readonly Router $router;

    public readonly HttpServer $httpServer;

    /** @var array<string, Api> */
    private array $apis;

    private readonly Routing $routeManager;

    private bool $mounted = false;

    private ?\Closure $pipeline = null;

    private ?Router $mountedRouter = null;

    public function __construct(private readonly Strapi $strapi)
    {
        $this->router = new Router('');
        $this->routeManager = Routing::createRouteManager($strapi);
        $this->httpServer = HttpServer::createHTTPServer($strapi, $this);

        $this->apis = [
            'content-api' => ContentApi::createContentAPI($strapi),
            'admin' => AdminApi::createAdminAPI($strapi),
        ];

        // init health check
        $this->router->add('ALL', '/_health', static function (ContextContract $ctx): void {
            $ctx->setHeader('strapi', 'You are so French!');
            $ctx->setStatus(204);
        }, ['method' => 'ALL', 'path' => '/_health', 'handler' => 'health', 'config' => ['auth' => false]]);
    }

    public static function createServer(Strapi $strapi): self
    {
        return new self($strapi);
    }

    public function api(string $name): Api
    {
        if (!isset($this->apis[$name])) {
            throw new \RuntimeException("API {$name} not found. Possible APIs are " . implode(',', array_keys($this->apis)));
        }

        return $this->apis[$name];
    }

    /** Add a global middleware (`callable(Context $ctx, callable $next): void`). */
    public function use(callable $middleware): static
    {
        $this->middlewares[] = $middleware;
        $this->pipeline = null;

        return $this;
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $routes a router with a `type` goes to that API, otherwise to the root router
     */
    public function routes(array $routes): static
    {
        if (!array_is_list($routes) && !empty($routes['type'])) {
            $type = (string) $routes['type'];
            if (!isset($this->apis[$type])) {
                throw new \RuntimeException("API {$type} not found. Possible APIs are " . implode(',', array_keys($this->apis)));
            }

            $this->apis[$type]->routes($routes);
            $this->mountedRouter = null;

            return $this;
        }

        $this->routeManager->addRoutes($routes, $this->router);
        $this->mountedRouter = null;

        return $this;
    }

    public function mount(): static
    {
        $this->mounted = true;
        $this->mountedRouter = null;

        return $this;
    }

    public function initRouting(): static
    {
        RegisterRoutes::registerAllRoutes($this->strapi);

        return $this;
    }

    public function initMiddlewares(): static
    {
        RegisterMiddlewares::registerApplicationMiddlewares($this->strapi);

        return $this;
    }

    /**
     * Every route: the APIs' routes (with their prefix) and the root router's.
     *
     * @return list<array{method: string, path: string, handler: callable, route: array<string, mixed>}>
     */
    public function listRoutes(): array
    {
        return $this->mountedRouter()->stack();
    }

    private function mountedRouter(): Router
    {
        if ($this->mountedRouter === null) {
            $router = new Router('');
            foreach ($this->apis as $api) {
                $api->mount($router);
            }
            $router->use($this->router);
            $this->mountedRouter = $router;
        }

        return $this->mountedRouter;
    }

    /** The router dispatch middleware: 404 (left to strapi::errors) and 405 with `Allow` like @koa/router. */
    private function dispatchMiddleware(): \Closure
    {
        return function (Context $ctx, callable $next): void {
            $match = $this->mountedRouter()->match($ctx->method(), $ctx->path());

            if ($match === null) {
                $next();

                return;
            }

            if (array_is_list($match)) {
                // allowedMethods(): 405 for known paths, 501 for unknown methods
                $ctx->setStatus(405);
                $ctx->setHeader('Allow', implode(', ', $match));
                if ($ctx->method() === 'OPTIONS') {
                    $ctx->setStatus(200);
                    $ctx->setBody('');
                }

                return;
            }

            $ctx->setParams($match['params']);
            ($match['handler'])($ctx, $next);
        };
    }

    /** Handle one PSR-7 request (FPM/FrankenPHP entry point and the test harness). */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->mounted) {
            $this->mount();
        }

        $keys = $this->strapi->config()->get('server.app.keys');
        $ctx = new Context($request, [
            'proxy' => (bool) $this->strapi->config()->get('server.proxy.koa', false),
            'keys' => is_array($keys) ? array_values(array_map('strval', $keys)) : null,
        ]);

        $pipeline = $this->pipeline ??= Compose::compose([...$this->middlewares, $this->dispatchMiddleware()]);

        $this->strapi->requestContext()->run($ctx, static function () use ($pipeline, $ctx): void {
            $pipeline($ctx);
        });

        $response = $ctx->toResponse();

        if ($ctx->method() === 'HEAD') {
            $response = $response->withBody(\Nyholm\Psr7\Stream::create(''));
        }

        return $response;
    }

    /**
     * Serve HTTP (blocking). Built on PHP's built-in web server; `$onListen` runs once the socket is bound.
     *
     * @param callable(): void|null $onListen
     */
    public function listen(string $host, int $port, ?callable $onListen = null): void
    {
        if (!$this->mounted) {
            $this->mount();
        }

        $this->httpServer->listen($host, $port, $onListen);
    }

    public function destroy(): void
    {
        $this->httpServer->destroy();
    }
}
