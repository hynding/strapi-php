<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Utils;

use Strapi\Core\Tests\BootedAppTestCase;
use Strapi\Core\Utils\Fetch;

/** fetch.ts has no upstream unit test: these cover {@see Fetch::intercept()}, which tests rely on. */
final class FetchTest extends BootedAppTestCase
{
    protected function tearDown(): void
    {
        Fetch::intercept(null);
    }

    public function testAnInterceptedRequestNeverReachesTheNetwork(): void
    {
        $seen = [];
        Fetch::intercept(static function (string $url, array $options) use (&$seen): array {
            $seen[] = [$url, $options['method'] ?? null];

            return ['status' => 201, 'headers' => ['X-Mocked' => 'yes'], 'body' => '{"ok":true}'];
        });

        $response = (self::strapi()->fetch())('https://mocked.invalid/a', ['method' => 'POST', 'body' => '{}']);

        self::assertSame(['ok' => true, 'status' => 201, 'headers' => ['x-mocked' => 'yes'], 'body' => '{"ok":true}'], $response);
        self::assertSame([['https://mocked.invalid/a', 'POST']], $seen);
    }

    public function testOpenStreamsTheInterceptedBody(): void
    {
        Fetch::intercept(static fn (string $url): array => ['status' => 404, 'statusText' => 'Not Found', 'body' => str_repeat('x', 70000)]);

        $response = self::strapi()->fetch()->open('https://mocked.invalid/file.bin', ['timeout' => 1]);

        self::assertSame(404, $response['status']);
        self::assertSame('Not Found', $response['statusText']);
        self::assertSame([], $response['headers']);
        self::assertSame('https://mocked.invalid/file.bin', $response['url']);
        self::assertSame(70000, strlen((string) stream_get_contents($response['stream'])));
        fclose($response['stream']);
    }

    public function testANullAnswerLetsTheRequestThroughAndInterceptNullRemovesTheHandler(): void
    {
        $calls = 0;
        Fetch::intercept(static function () use (&$calls): ?array {
            ++$calls;

            return null;
        });

        // a refused connection: the request went past the handler
        foreach ([null, 'removed'] as $round) {
            try {
                (self::strapi()->fetch())('http://127.0.0.1:1/', ['timeout' => 1]);
                self::fail('the request should fail');
            } catch (\RuntimeException $error) {
                self::assertStringStartsWith('fetch failed: ', $error->getMessage());
            }
            if ($round === null) {
                Fetch::intercept(null);
            }
        }

        self::assertSame(1, $calls);
    }

    public function testARealResponseCarriesItsStatusAndHeaders(): void
    {
        $router = tempnam(sys_get_temp_dir(), 'fetch-router');
        file_put_contents($router, '<?php http_response_code(201); header("X-Probe: yes"); header("Content-Type: application/json"); echo "{\"ok\":true}";');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $server = proc_open([PHP_BINARY, '-S', $address, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($server);

        try {
            $response = null;
            for ($attempt = 0; $attempt < 50 && $response === null; ++$attempt) {
                try {
                    $response = (self::strapi()->fetch())("http://{$address}/", ['method' => 'POST', 'body' => 'a=1', 'timeout' => 1]);
                } catch (\RuntimeException) {
                    usleep(100_000);
                }
            }

            self::assertNotNull($response, 'the probe server did not start');
            self::assertSame([true, 201, 'yes', '{"ok":true}'], [$response['ok'], $response['status'], $response['headers']['x-probe'] ?? null, $response['body']]);
        } finally {
            proc_terminate($server);
            proc_close($server);
            unlink($router);
        }
    }
}
