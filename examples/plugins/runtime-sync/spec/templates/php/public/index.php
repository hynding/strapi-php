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

// Static files when running under the built-in server (`php -S ... public/index.php`)
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path !== '/' && is_file(__DIR__ . $path) && basename($path) !== 'index.php') {
        return false;
    }
}

ignore_user_abort(true);

$strapi = Strapi::createStrapi(['appDir' => $root, 'distDir' => $root, 'serveAdminPanel' => true])->load();
$server = $strapi->server()->mount();

$handle = static function () use ($server): void {
    HttpServer::emit($server->handle(HttpServer::requestFromGlobals()));
};

if (function_exists('frankenphp_handle_request')) {
    // FrankenPHP worker mode: the application stays loaded across requests
    $maxRequests = (int) (getenv('MAX_REQUESTS') ?: 0);
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
