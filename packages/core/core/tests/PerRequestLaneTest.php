<?php

declare(strict_types=1);

namespace Strapi\Core\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\HttpServer;
use Symfony\Component\Process\Process;

/**
 * PHP port: examples/getstarted served by PHP's built-in server in production mode. Like PHP-FPM,
 * CGI and mod_php it runs every request in a fresh process state, so each request boots: what
 * upstream's suite cannot see (its instance lives as long as the test). The first request settles
 * the bootstrap; the rest are settled boots, which must serve admin auth, API tokens, the content
 * API and GraphQL, keep rate limits across requests, and log nothing of their boots.
 */
final class PerRequestLaneTest extends TestCase
{
    private string $dir = '';

    private ?Process $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/strapi-per-request-lane-' . bin2hex(random_bytes(6));
        mkdir($this->dir);

        $app = dirname(__DIR__, 4) . '/examples/getstarted';
        $this->port = self::freePort();
        // the server writes to files, not to pipes this test would have to drain (a full pipe
        // blocks the server mid-request); `exec` so that stopping the process stops PHP itself
        // PHP 8.3's function JIT (`opcache.jit=1235`, setup-php's default on CI) segfaults on some
        // fresh boots, on main too (php-cgi exit code 139; locally 4 boots in 10 here, 1 in 15 on main): a
        // PHP bug, not what these tests check (docs/README.md, "Runtime model")
        $command = HttpServer::command(PHP_BINARY, '127.0.0.1', $this->port, "{$app}/public", "{$app}/public/index.php");
        array_splice($command, 1, 0, ['-d', 'opcache.jit=disable']);
        $command = implode(' ', array_map(escapeshellarg(...), $command));
        $server = Process::fromShellCommandline(
            "exec {$command} >> " . escapeshellarg("{$this->dir}/server.out") . ' 2>> ' . escapeshellarg("{$this->dir}/server.err"),
            $app,
            [
                'NODE_ENV' => 'production',
                'DATABASE_CLIENT' => 'sqlite',
                'DATABASE_FILENAME' => "{$this->dir}/data.db",
                'LOG_LEVEL' => 'info',
                'APP_KEYS' => 'lane-key-1,lane-key-2',
                'API_TOKEN_SALT' => 'lane-api-token-salt',
                'ADMIN_JWT_SECRET' => 'lane-admin-jwt-secret',
                'TRANSFER_TOKEN_SALT' => 'lane-transfer-token-salt',
                'JWT_SECRET' => 'lane-jwt-secret',
                'ENCRYPTION_KEY' => 'lane-encryption-key',
            ],
        );
        $server->setTimeout(null);
        $server->start();
        $this->server = $server;
        self::waitFor($this->port, $server);
        self::assertTrue($server->isRunning(), $this->serverOutput());
    }

    /** What the server printed: its logs (stdout) and the built-in server's own (stderr). */
    private function serverOutput(): string
    {
        return (string) @file_get_contents("{$this->dir}/server.out") . (string) @file_get_contents("{$this->dir}/server.err");
    }

    protected function tearDown(): void
    {
        $this->server?->stop(0);
        array_map('unlink', glob(self::cacheDir() . '/*.php') ?: []);
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{0: int, 1: mixed}
     */
    private function send(string $method, string $path, ?array $json = null, ?string $token = null): array
    {
        $headers = $json !== null ? "Content-Type: application/json\r\n" : '';
        $headers .= $token !== null ? "Authorization: Bearer {$token}\r\n" : '';
        $started = microtime(true);
        $body = @file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, stream_context_create(['http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $json !== null ? (string) json_encode($json) : '',
            'ignore_errors' => true,
            'timeout' => 30,
            'proxy' => '',
        ]]));
        // populated by the http wrapper on every completed request
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header;
        if ($body === false && $responseHeaders === []) {
            $error = error_get_last()['message'] ?? 'unknown error';
            $elapsed = microtime(true) - $started;
            // what the same request gets on a raw socket, and the server a moment later
            $raw = $this->rawRequest($method, $path, $headers, $json !== null ? (string) json_encode($json) : '');
            usleep(200_000);
            self::fail(sprintf(
                "%s %s: no response after %.2f s (%s); a raw retry got %s; server %s\n%s",
                $method,
                $path,
                $elapsed,
                $error,
                $raw,
                $this->server?->isRunning() === true ? 'running' : 'exited with ' . var_export($this->server?->getExitCode(), true),
                substr($this->serverOutput(), -4000),
            ));
        }
        preg_match('~^HTTP/\S+\s+(\d{3})~', $responseHeaders[0] ?? '', $m);

        return [(int) ($m[1] ?? 0), is_string($body) ? json_decode($body, true) : null];
    }

    /** For failure messages: the bytes a raw HTTP/1.0 request receives, with timing and errors. */
    private function rawRequest(string $method, string $path, string $headers, string $content): string
    {
        $started = microtime(true);
        $socket = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 5);
        if ($socket === false) {
            return "no connection ({$errno} {$errstr})";
        }
        stream_set_timeout($socket, 30);
        fwrite($socket, "{$method} {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\n{$headers}Content-Length: " . strlen($content) . "\r\n\r\n{$content}");
        $received = (string) stream_get_contents($socket);
        $timedOut = stream_get_meta_data($socket)['timed_out'];
        fclose($socket);

        return sprintf('%d bytes after %.2f s%s: %s', strlen($received), microtime(true) - $started, $timedOut ? ' (timed out)' : '', var_export(substr($received, 0, 300), true));
    }

    public function testSettledBootsServeAdminAuthTokensTheContentApiAndGraphql(): void
    {
        // the first request settles the bootstrap
        self::assertSame(204, $this->send('GET', '/_health')[0], $this->serverOutput());

        // registration is rate limited by email, and the counts outlive the run: an email of its own
        $email = 'ada-' . bin2hex(random_bytes(4)) . '@example.com';
        [$status, $body] = $this->send('POST', '/admin/register-admin', ['firstname' => 'Ada', 'lastname' => 'Admin', 'email' => $email, 'password' => 'Passw0rdPassw0rd']);
        self::assertSame(200, $status, (string) json_encode($body));
        $admin = (string) ($body['data']['token'] ?? '');
        self::assertSame(200, $this->send('GET', '/admin/users/me', token: $admin)[0]);
        self::assertSame(401, $this->send('GET', '/admin/users/me')[0]);

        [$status, $body] = $this->send('POST', '/admin/api-tokens', ['name' => 'lane', 'type' => 'full-access', 'lifespan' => null], $admin);
        self::assertSame(201, $status, (string) json_encode($body));
        $token = (string) ($body['data']['accessKey'] ?? '');

        self::assertSame(403, $this->send('GET', '/api/articles')[0]);
        [$status, $body] = $this->send('POST', '/api/articles', ['data' => ['title' => 'Per request', 'authorName' => 'lane']], $token);
        self::assertSame(201, $status, (string) json_encode($body));
        $documentId = (string) ($body['data']['documentId'] ?? '');
        [$status, $body] = $this->send('GET', "/api/articles/{$documentId}", token: $token);
        self::assertSame([200, 'Per request'], [$status, $body['data']['title'] ?? null]);

        // GraphQL is built on its first request in production
        [$status, $body] = $this->send('POST', '/graphql', ['query' => '{ articles { documentId title } }'], $token);
        self::assertSame(200, $status, (string) json_encode($body));
        self::assertSame([['documentId' => $documentId, 'title' => 'Per request']], $body['data']['articles'] ?? null);

        // only the first boot logged its startup and shutdown; settled boots log their request
        $output = $this->serverOutput();
        self::assertSame(1, substr_count($output, 'Shutting down Strapi'), $output);

        // settled boots keep the database metadata and the route table in the boot cache, where
        // opcache keeps scripts in shared memory (the built-in server: `opcache.enable`)
        if (function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL) && !filter_var(ini_get('opcache.file_cache_only'), FILTER_VALIDATE_BOOL)) {
            self::assertCount(1, glob(self::cacheDir() . '/db-metadata.*.php') ?: [], $output);
            self::assertCount(1, glob(self::cacheDir() . '/routes.*.php') ?: [], $output);
        }
    }

    private static function cacheDir(): string
    {
        return dirname(__DIR__, 4) . '/examples/getstarted/.strapi/cache';
    }

    public function testRateLimitsCountAcrossRequests(): void
    {
        // the counts outlive the run (.tmp/rate-limit, a 5-minute window): a key of this run's own
        $email = 'nobody-' . bin2hex(random_bytes(4)) . '@example.com';
        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->send('POST', '/admin/login', ['email' => $email, 'password' => 'wrong'])[0];
        }

        self::assertSame([400, 400, 400, 400, 400, 429], $statuses);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitFor(int $port, Process $server): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }

        self::fail('The built-in server did not start (exit code ' . var_export($server->getExitCode(), true) . ')');
    }
}
