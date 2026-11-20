<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Utils\Sapi;

/**
 * The example app served by `php-cgi` (SAPI cgi-fcgi), as a CGI web server runs it: each request
 * a new process. Logs go to PHP's error_log, not into the response, and a settled boot logs only
 * the request.
 */
final class PerRequestSapiTest extends TestCase
{
    private string $dir = '';

    public function testTheSapiLists(): void
    {
        foreach (['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed'] as $sapi) {
            self::assertTrue(Sapi::isPerRequest($sapi), $sapi);
            self::assertTrue(Sapi::hasNoConsole($sapi), $sapi);
        }
        self::assertTrue(Sapi::isPerRequest('cli-server'));
        self::assertFalse(Sapi::hasNoConsole('cli-server'));
        foreach (['cli', 'frankenphp'] as $sapi) {
            self::assertFalse(Sapi::isPerRequest($sapi, frankenphpWorker: true), $sapi);
            self::assertFalse(Sapi::hasNoConsole($sapi), $sapi);
        }
        // FrankenPHP's classic mode boots every request; its worker mode once
        self::assertTrue(Sapi::isPerRequest('frankenphp', frankenphpWorker: false));
        putenv('FRANKENPHP_WORKER=1');
        try {
            self::assertFalse(Sapi::isPerRequest('frankenphp'));
        } finally {
            putenv('FRANKENPHP_WORKER');
        }
        self::assertTrue(Sapi::isPerRequest('frankenphp'));
        // before FrankenPHP 1.12.5 only $_SERVER has it
        $_SERVER['FRANKENPHP_WORKER'] = '1';
        try {
            self::assertFalse(Sapi::isPerRequest('frankenphp'));
        } finally {
            unset($_SERVER['FRANKENPHP_WORKER']);
        }
    }

    protected function tearDown(): void
    {
        // what settled boots wrote to the boot cache (.strapi/cache)
        array_map('unlink', glob(dirname(__DIR__, 5) . '/examples/getstarted/.strapi/cache/*.php') ?: []);
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }
    }

    private static function phpCgi(): ?string
    {
        $candidate = dirname(PHP_BINARY) . '/php-cgi';
        if (is_executable($candidate)) {
            return $candidate;
        }
        $found = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));

        return $found !== '' && is_executable($found) ? $found : null;
    }

    /** What php-cgi printed to stderr on the last request, for failure messages. */
    private string $stderr = '';

    /** @param list<string> $log */
    private function diagnostics(string $response, array $log): string
    {
        return sprintf("response:\n%s\nstderr:\n%s\nerror log:\n%s", substr($response, 0, 2000), substr($this->stderr, -4000), implode("\n", array_slice($log, -40)));
    }

    /** @return array{0: string, 1: list<string>} the raw response and the error_log lines */
    private function request(string $phpCgi, string $uri, string $environment): array
    {
        $app = dirname(__DIR__, 5) . '/examples/getstarted';
        $env = [
            'REDIRECT_STATUS' => '200', 'GATEWAY_INTERFACE' => 'CGI/1.1', 'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => "{$app}/public/index.php",
            'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REMOTE_ADDR' => '127.0.0.1',
            'DATABASE_CLIENT' => 'sqlite', 'DATABASE_FILENAME' => "{$this->dir}/data.db", 'JWT_SECRET' => 'per-request-sapi-test',
            'LOG_LEVEL' => 'info', 'PATH' => (string) getenv('PATH'),
            // development never settles
            'NODE_ENV' => $environment, 'APP_KEYS' => 'sapi-key-1,sapi-key-2', 'API_TOKEN_SALT' => 'sapi-api-token-salt',
            'ADMIN_JWT_SECRET' => 'sapi-admin-jwt-secret', 'TRANSFER_TOKEN_SALT' => 'sapi-transfer-token-salt',
            'ENCRYPTION_KEY' => 'sapi-encryption-key',
        ];
        $process = proc_open(
            // PHP 8.3's function JIT (`opcache.jit=1235`, setup-php's default on CI) segfaults on some
            // fresh boots, on main too (php-cgi exit code 139; locally 4 boots in 10 here, 1 in 15 on main): a
            // PHP bug, not what these tests check (docs/README.md, "Runtime model")
            [$phpCgi, '-d', 'opcache.jit=disable', '-d', "error_log={$this->dir}/error.log", '-d', 'log_errors=1', '-d', 'display_errors=0', "{$app}/public/index.php"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $app,
            $env,
        );
        self::assertIsResource($process);
        $response = (string) stream_get_contents($pipes[1]);
        $this->stderr = (string) stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);
        $this->stderr .= "\n(php-cgi exit code {$exitCode})";

        $log = is_file("{$this->dir}/error.log") ? file("{$this->dir}/error.log", FILE_IGNORE_NEW_LINES) : [];

        return [$response, $log === false ? [] : $log];
    }

    /** @return iterable<string, array{string}> */
    public static function environments(): iterable
    {
        yield 'production' => ['production'];
        // outside production the documentation plugin rewrites its spec at every boot
        yield 'staging' => ['staging'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('environments')]
    public function testLogsGoToTheErrorLogAndASettledBootLogsOnlyTheRequest(string $environment): void
    {
        $phpCgi = self::phpCgi();
        if ($phpCgi === null) {
            self::markTestSkipped('php-cgi is not installed');
        }
        $this->dir = sys_get_temp_dir() . '/strapi-per-request-' . bin2hex(random_bytes(6));
        mkdir($this->dir);

        [$response, $log] = $this->request($phpCgi, '/_health', $environment);
        // the CGI response starts with its headers: no log line in front of them
        self::assertStringStartsWith('Status: 204', $response, $this->diagnostics($response, $log));
        self::assertNotEmpty(preg_grep('/strapi\.INFO: Shutting down Strapi/', $log), 'an unsettled boot logs its lifecycle');
        $before = count($log);

        [$response, $log] = $this->request($phpCgi, '/_health', $environment);
        self::assertStringStartsWith('Status: 204', $response, $this->diagnostics($response, $log));
        $added = implode("\n", array_slice($log, $before));
        self::assertMatchesRegularExpression('/strapi\.INFO: GET \/_health \(\d+ ms\) 204/', $added);
        self::assertStringNotContainsString('Shutting down Strapi', $added, 'a settled boot does not log its lifecycle');
    }
}
