#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Smoke test: boots examples/getstarted on an in-memory SQLite database and sends three requests
 * through `strapi.server.handle()`. Exit code 0 when everything matches, 1 otherwise.
 *
 *     php tests/php/smoke.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('DATABASE_CLIENT=sqlite');
putenv('DATABASE_FILENAME=:memory:');
putenv('STRAPI_NO_EXIT=1');
putenv('LOG_LEVEL=error');
$_ENV['LOG_LEVEL'] = 'error';
$_ENV['DATABASE_CLIENT'] = 'sqlite';
$_ENV['DATABASE_FILENAME'] = ':memory:';

$appDir = dirname(__DIR__, 2) . '/examples/getstarted';

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
    if (!$ok) {
        $failures++;
    }
};

try {
    $strapi = \Strapi\Core\Core::createStrapi(['appDir' => $appDir])->load();
} catch (\Throwable $e) {
    echo "FAIL boot: {$e->getMessage()}\n";
    exit(1);
}
echo "booted examples/getstarted (" . count($strapi->contentTypes()) . " content types)\n";

$factory = new \Nyholm\Psr7\Factory\Psr17Factory();
$send = static function (string $method, string $uri, ?array $json = null) use ($strapi, $factory): array {
    $request = $factory->createServerRequest($method, 'http://localhost:1337' . $uri);
    if ($json !== null) {
        $request = $request->withHeader('Content-Type', 'application/json')->withBody($factory->createStream((string) json_encode($json)));
    }
    $response = $strapi->server()->handle($request);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true), $response];
};

// 1. empty collection
[$status, $body] = $send('GET', '/api/articles');
$check('GET /api/articles → 200', $status === 200, "got {$status}");
$check('empty envelope', $body === ['data' => [], 'meta' => ['pagination' => ['page' => 1, 'pageSize' => 25, 'pageCount' => 0, 'total' => 0]]], json_encode($body));

// 2. create
[$status, $body] = $send('POST', '/api/articles', ['data' => ['title' => 'Smoke test', 'authorName' => 'smoke']]);
$check('POST /api/articles → 201', $status === 201, "got {$status}: " . json_encode($body));
$documentId = $body['data']['documentId'] ?? '';
$check('flat entity with documentId', is_string($documentId) && strlen($documentId) === 24 && ($body['data']['title'] ?? null) === 'Smoke test');

// 3. find one
[$status, $body, $response] = $send('GET', "/api/articles/{$documentId}");
$check("GET /api/articles/{$documentId} → 200", $status === 200, "got {$status}");
$check('same document', ($body['data']['documentId'] ?? null) === $documentId);
$check('X-Powered-By header', $response->getHeaderLine('X-Powered-By') === 'Strapi <strapi.io>');

$strapi->destroy();

echo $failures === 0 ? "\nsmoke: all checks passed\n" : "\nsmoke: {$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
