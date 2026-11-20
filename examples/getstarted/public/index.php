<?php

declare(strict_types=1);

/**
 * Strapi front controller (`public/index.php`), generated from strapi/strapi `templates/index.php`.
 *
 * Serves every request of the application under PHP-FPM (one request per execution), PHP's
 * built-in server (`bin/strapi start` / `strapi develop`) and FrankenPHP worker mode, where the
 * application is booted once and `frankenphp_handle_request()` loops over the requests.
 *
 *   FrankenPHP Caddyfile:  php_server { worker ./public/index.php }
 *   nginx + FPM:           try_files $uri /index.php$is_args$args;
 */

use Strapi\Cli\Strapi;
use Strapi\Core\Services\Server\HttpServer;

$root = dirname(__DIR__);

$autoloads = [$root . '/vendor/autoload.php', dirname($root, 2) . '/vendor/autoload.php'];
foreach ($autoloads as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

if (!class_exists(Strapi::class)) {
    // no vendor/: run `composer install`, or upload the bundle `php bin/strapi bundle` makes
    error_log('[strapi] vendor/autoload.php is missing: run `composer install` in ' . $root . ', or upload the bundle of `php bin/strapi bundle`.');
    http_response_code(500);

    return;
}

// Static files when running under the built-in server (`php -S ... public/index.php`)
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path !== '/' && is_file(__DIR__ . $path) && basename($path) !== 'index.php') {
        return false;
    }
}

ignore_user_abort(true);

try {
    $strapi = Strapi::createStrapi(['appDir' => $root, 'distDir' => $root, 'serveAdminPanel' => true])->load();
    $server = $strapi->server()->mount();
} catch (\Throwable $error) {
    // logged to PHP's error log with what the host lacks (PHP-FPM, CGI...); rethrown elsewhere
    HttpServer::bootFailed($error, $root);

    return;
}

$handle = static function () use ($server): void {
    HttpServer::emit($server->handle(HttpServer::requestFromGlobals()));
};

// FrankenPHP puts FRANKENPHP_WORKER (and MAX_REQUESTS) in $_SERVER; getenv() sees them from 1.12.5 on
if (function_exists('frankenphp_handle_request') && ($_SERVER['FRANKENPHP_WORKER'] ?? getenv('FRANKENPHP_WORKER')) === '1') {
    // FrankenPHP worker mode: the application stays loaded across requests. FrankenPHP's classic
    // mode (php_server without `worker`) has the function too, but it throws there: one request
    $maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? getenv('MAX_REQUESTS') ?: 0);
    $handled = 0;

    do {
        $keepRunning = \frankenphp_handle_request($handle);

        gc_collect_cycles();
        $handled++;
    } while ($keepRunning && ($maxRequests === 0 || $handled < $maxRequests));

    $strapi->destroy();

    return;
}

// PHP-FPM / built-in server: one request per execution
$handle();

$strapi->destroy();
