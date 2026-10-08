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

$strapi->load();
$server = $strapi->server()->mount();
$bridge = new Bridge($strapi);
$factory = new Psr17Factory();

$handle = static function () use ($server, $bridge, $factory): void {
    $request = HttpServer::requestFromGlobals();

    if ($request->getMethod() === 'POST' && $request->getUri()->getPath() === Bridge::PATH) {
        $payload = json_decode((string) $request->getBody(), true);
        ['status' => $status, 'body' => $body] = $bridge->handle(is_array($payload) ? $payload : []);
        HttpServer::emit($factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream((string) json_encode($body, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))));

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
