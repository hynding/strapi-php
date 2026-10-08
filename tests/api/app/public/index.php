<?php

declare(strict_types=1);

/**
 * Worker script for upstream's API suite (tests/api). The project's usual public/index.php,
 * plus what upstream's in-process `createStrapiInstance()` (packages/utils/api-tests/strapi.js)
 * does to the instance:
 *
 * - `POST /__api-tests/rpc` replays a recorded `strapi.*` expression on this instance
 *   ({@see \Strapi\ApiTests\Bridge}); tests/api/lib/bridge.js is the client.
 * - `STRAPI_API_TESTS_BYPASS_AUTH=1` registers upstream's `test-auth` content-api strategy.
 * - `STRAPI_API_TESTS_PHASES=1` reproduces where upstream runs the `register` / `bootstrap`
 *   callbacks of `createStrapiInstance()`: the worker serves bridge calls before `register()` until
 *   `POST /__api-tests/phase {"phase":"register"}`, then again at the start of the modules'
 *   bootstrap (after the database is ready, before plugins/admin bootstrap) until
 *   `{"phase":"bootstrap"}`. Requests that arrive meanwhile wait for the worker, as they would for
 *   a server that is not listening yet; `/_health` answers 204 so the harness knows it is up.
 *
 * Run with exactly one FrankenPHP worker: state set through the bridge (config, listeners) lives
 * in this process and must be the one serving the HTTP requests.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use Strapi\ApiTests\Bridge;
use Strapi\Cli\Strapi;
use Strapi\Core\Services\Server\HttpServer;

$root = dirname(__DIR__);

foreach ([$root . '/vendor/autoload.php', getenv('STRAPI_API_TESTS_AUTOLOAD') ?: ''] as $autoload) {
    if ($autoload !== '' && is_file($autoload)) {
        require $autoload;
        break;
    }
}

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path !== '/' && is_file(__DIR__ . $path) && basename($path) !== 'index.php') {
        return false;
    }
}

ignore_user_abort(true);

// PHP notices/deprecations go to the server log, never into a response body (a bridge reply is JSON)
ini_set('display_errors', 'stderr');

$strapi = Strapi::createStrapi(['appDir' => $root, 'distDir' => $root, 'autoReload' => true]);

// upstream: instance.config.set('server.proxy.koa', true) so asHTTPS() can fake X-Forwarded-Proto
$strapi->config()->set('server.proxy.koa', true);

if (getenv('STRAPI_API_TESTS_BYPASS_AUTH') === '1') {
    $strapi->get('auth')->register('content-api', [
        'name' => 'test-auth',
        'authenticate' => static fn (): array => ['authenticated' => true],
        'verify' => static function (): void {
        },
    ]);
}

$bridge = new Bridge($strapi);
$factory = new Psr17Factory();

$emitJson = static function (int $status, array $body) use ($factory): void {
    HttpServer::emit($factory->createResponse($status)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream((string) json_encode($body, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))));
};

/** Serves bridge calls (and `/_health`) until the harness posts `$phase` to `/__api-tests/phase`. */
$pauseUntil = static function (string $phase) use ($bridge, $emitJson): void {
    if (!function_exists('frankenphp_handle_request') || getenv('STRAPI_API_TESTS_PHASES') !== '1') {
        return;
    }

    $reached = false;
    while (!$reached) {
        $keepRunning = \frankenphp_handle_request(static function () use ($bridge, $emitJson, $phase, &$reached): void {
            $request = HttpServer::requestFromGlobals();
            $path = $request->getUri()->getPath();
            $payload = json_decode((string) $request->getBody(), true);
            $payload = is_array($payload) ? $payload : [];

            if ($request->getMethod() === 'POST' && $path === '/__api-tests/phase') {
                $reached = ($payload['phase'] ?? null) === $phase;
                $emitJson($reached ? 200 : 409, ['phase' => $phase]);

                return;
            }
            if ($request->getMethod() === 'POST' && $path === Bridge::PATH) {
                ['status' => $status, 'body' => $body] = $bridge->handle($payload);
                $emitJson($status, $body);

                return;
            }
            if ($path === '/_health') {
                HttpServer::emit((new \Nyholm\Psr7\Response(204))->withHeader('strapi', 'You are so French!'));

                return;
            }
            $emitJson(503, ['error' => "Strapi is waiting for the {$phase} phase"]);
        });
        if (!$keepRunning) {
            exit(0);
        }
    }
};

$warnings = new \ArrayObject();

// upstream wraps `modules.bootstrap` to run the test's `bootstrap` callback first
$modules = $strapi->get('modules');
$strapi->set('modules', new class ($modules, $pauseUntil, $warnings) {
    /**
     * @param \Closure(string): void $pauseUntil
     * @param \ArrayObject<int, string> $warnings
     */
    public function __construct(private readonly object $modules, private readonly \Closure $pauseUntil, private readonly \ArrayObject $warnings)
    {
    }

    public function bootstrap(): mixed
    {
        ($this->pauseUntil)('bootstrap');
        // a spy installed by the bootstrap callback only sees what is logged from here on
        $this->warnings->exchangeArray([]);

        return $this->modules->bootstrap();
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return $this->modules->{$name}(...$args);
    }
});

/**
 * Upstream tests spy on `strapi.log.warn` from their bootstrap callback; across the process
 * boundary the warnings logged while loading are recorded and handed to the harness
 * (`POST /__api-tests/warnings`), which replays them through the test's `strapi.log.warn`.
 */
$logger = $strapi->log();
$strapi->set('logger', new class ($logger, $warnings) extends \Psr\Log\AbstractLogger {
    /** @param \ArrayObject<int, string> $warnings */
    public function __construct(private readonly \Psr\Log\LoggerInterface $logger, private readonly \ArrayObject $warnings)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === \Psr\Log\LogLevel::WARNING) {
            $this->warnings->append((string) $message);
        }
        $this->logger->log($level, $message, $context);
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return $this->logger->{$name}(...$args);
    }
});

$pauseUntil('register');
$strapi->load();
$server = $strapi->server()->mount();
$strapi->set('logger', $logger);

$handle = static function () use ($server, $bridge, $emitJson, $warnings): void {
    $request = HttpServer::requestFromGlobals();

    if ($request->getMethod() === 'POST' && $request->getUri()->getPath() === Bridge::PATH) {
        $payload = json_decode((string) $request->getBody(), true);
        ['status' => $status, 'body' => $body] = $bridge->handle(is_array($payload) ? $payload : []);
        $emitJson($status, $body);

        return;
    }

    if ($request->getMethod() === 'POST' && $request->getUri()->getPath() === '/__api-tests/warnings') {
        $emitJson(200, ['warnings' => $warnings->getArrayCopy()]);

        return;
    }

    HttpServer::emit($server->handle($request));
};

if (function_exists('frankenphp_handle_request')) {
    do {
        $keepRunning = \frankenphp_handle_request($handle);
        gc_collect_cycles();
    } while ($keepRunning);

    $strapi->destroy();

    return;
}

$handle();
$strapi->destroy();
