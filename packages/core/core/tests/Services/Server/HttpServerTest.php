<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services\Server;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\HttpServer;
use Symfony\Component\Process\Process;

/**
 * Not a port: upstream's server is Node's http module. These cover the built-in server that
 * `strapi develop` and `strapi start` run, and that it leaves a multipart body unparsed, so an
 * upload of several files under one field name reaches strapi::body whole (BodyTest covers the
 * parsing), as koa-body gives them all to the upload plugin.
 */
final class HttpServerTest extends TestCase
{
    public function testTheBuiltInServerLeavesMultipartBodiesToStrapiBody(): void
    {
        self::assertSame(
            ['/usr/bin/php', '-d', 'enable_post_data_reading=0', '-S', '0.0.0.0:1337', '-t', '/app/public', '/app/public/index.php'],
            HttpServer::command('/usr/bin/php', '0.0.0.0', 1337, '/app/public', '/app/public/index.php'),
        );
    }

    public function testAMultipartBodyReachesTheAppUnparsed(): void
    {
        $dir = sys_get_temp_dir() . '/strapi-http-server-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        // a router that reports what PHP parsed itself and what it left in php://input
        file_put_contents($dir . '/index.php', '<?php echo json_encode(["files" => array_keys($_FILES), "input" => file_get_contents("php://input")]);');

        $port = self::freePort();
        $server = new Process(HttpServer::command(PHP_BINARY, '127.0.0.1', $port, $dir, $dir . '/index.php'), $dir);
        $server->start();
        try {
            self::waitFor($port, $server);
            $boundary = 'strapi' . bin2hex(random_bytes(6));
            $part = static fn (string $name, string $content): string => "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"files\"; filename=\"{$name}\"\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n";
            $payload = $part('one.txt', 'first') . $part('two.txt', 'second') . "--{$boundary}--\r\n";
            $response = (string) file_get_contents("http://127.0.0.1:{$port}/api/upload", false, stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: multipart/form-data; boundary={$boundary}\r\n",
                'content' => $payload,
                'ignore_errors' => true,
            ]]));

            $seen = json_decode($response, true);
            self::assertIsArray($seen, $response . $server->getErrorOutput());
            // parsed by PHP, $_FILES would hold two.txt only; instead the whole body is left for strapi::body
            self::assertSame([], $seen['files']);
            self::assertSame($payload, $seen['input']);
        } finally {
            $server->stop(0);
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
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
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            if (!$server->isRunning()) {
                self::fail('the built-in server stopped: ' . $server->getErrorOutput());
            }
            usleep(50_000);
        }
        self::fail('the built-in server did not start');
    }

    /**
     * Apache drops the Authorization header for PHP-FPM, FastCGI and CGI; public/.htaccess passes
     * it as an environment variable, REDIRECT_-prefixed after the rewrite to index.php.
     */
    public function testTheAuthorizationHeaderIsRestoredFromTheServerVariables(): void
    {
        self::assertSame('Bearer a', HttpServer::authorizationFromServer(['HTTP_AUTHORIZATION' => 'Bearer a']));
        self::assertSame('Bearer b', HttpServer::authorizationFromServer(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer b']));
        self::assertSame('Bearer a', HttpServer::authorizationFromServer(['HTTP_AUTHORIZATION' => 'Bearer a', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer b']));
        self::assertSame('Basic ' . base64_encode('ada:secret'), HttpServer::authorizationFromServer(['PHP_AUTH_USER' => 'ada', 'PHP_AUTH_PW' => 'secret']));
        self::assertSame('Digest username="ada"', HttpServer::authorizationFromServer(['PHP_AUTH_DIGEST' => 'username="ada"']));
        self::assertNull(HttpServer::authorizationFromServer(['HTTP_AUTHORIZATION' => '', 'HTTP_HOST' => 'localhost']));
    }

    public function testARequestFromGlobalsGetsTheRestoredAuthorizationHeader(): void
    {
        $server = $_SERVER;
        try {
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/users/me', 'HTTP_HOST' => 'localhost', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer token'];

            self::assertSame('Bearer token', HttpServer::requestFromGlobals()->getHeaderLine('Authorization'));
        } finally {
            $_SERVER = $server;
        }
    }
}
