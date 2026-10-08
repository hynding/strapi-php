<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Websocket;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Not an upstream file: a small blocking HTTP/1.1 server for the data-transfer WebSocket routes.
 *
 * PHP under FPM or FrankenPHP cannot keep an upgraded connection, so `strapi transfer:serve`
 * runs this long-lived process and the reverse proxy routes `/admin/transfer/runner/*` to it.
 * Each request is turned into a PSR-7 request carrying an {@see Upgrade} attribute and handed to
 * the application (the regular Strapi router, middlewares and auth strategies run); a controller
 * that upgrades keeps the connection until the transfer ends, other responses are written back.
 * Connections are served one at a time (a transfer is one long connection).
 */
final class Server
{
    /** @var resource|null */
    private mixed $server = null;

    private bool $running = false;

    public function __construct(private readonly string $host, private readonly int $port)
    {
    }

    /** Bind the listening socket; returns the bound port (useful with port 0). */
    public function listen(): int
    {
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException("Could not listen on {$this->host}:{$this->port}: {$errstr}");
        }
        $this->server = $server;

        $name = (string) stream_socket_get_name($server, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Accept and serve connections until {@see self::stop()} is called.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function serve(callable $handler, ?callable $onError = null): void
    {
        if ($this->server === null) {
            $this->listen();
        }
        $listening = $this->server;
        if ($listening === null) {
            throw new \RuntimeException('The server is not listening');
        }

        $this->running = true;

        while ($this->running) {
            $read = [$listening];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) < 1) {
                continue;
            }

            $socket = @stream_socket_accept($listening, 1, $peer);
            if ($socket === false) {
                continue;
            }

            try {
                $this->handleConnection($socket, (string) $peer, $handler);
            } catch (\Throwable $error) {
                if ($onError !== null) {
                    $onError($error);
                }
            } finally {
                if (is_resource($socket)) {
                    @fclose($socket);
                }
            }
        }

        if (is_resource($listening)) {
            fclose($listening);
        }
        $this->server = null;
    }

    /**
     * @param resource                                            $socket
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    private function handleConnection(mixed $socket, string $peer, callable $handler): void
    {
        stream_set_timeout($socket, 30);

        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                return;
            }
            $head .= $chunk;
            if (strlen($head) > 65536) {
                self::writeRaw($socket, 431, 'Request Header Fields Too Large');

                return;
            }
        }

        [$headPart, $rest] = explode("\r\n\r\n", $head, 2);
        $lines = explode("\r\n", $headPart);
        $requestLine = (string) array_shift($lines);
        if (preg_match('#^([A-Z]+)\s+(\S+)\s+HTTP/(\d\.\d)$#', $requestLine, $m) !== 1) {
            self::writeRaw($socket, 400, 'Bad Request');

            return;
        }
        [, $method, $target, $protocol] = $m;

        $headers = [];
        $rawHeaders = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            $headers[strtolower($name)] = isset($headers[strtolower($name)]) ? $headers[strtolower($name)] . ', ' . $value : $value;
            $rawHeaders[$name][] = $value;
        }

        $isUpgrade = isset($headers['upgrade']);

        // a request body (not expected on these routes) is read when it is announced
        $body = '';
        if (!$isUpgrade && isset($headers['content-length'])) {
            $length = (int) $headers['content-length'];
            $body = $rest;
            while (($missing = $length - strlen($body)) > 0) {
                $chunk = fread($socket, $missing);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
        }

        $hostHeader = $headers['host'] ?? $this->host;
        $uri = (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) ? $target : "http://{$hostHeader}{$target}";
        $peerHost = (string) preg_replace('/:\d+$/', '', $peer);

        $upgrade = new Upgrade($socket, $headers, $isUpgrade ? $rest : '');

        $serverParams = [
            'REMOTE_ADDR' => trim($peerHost, '[]'),
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $target,
            'SERVER_PROTOCOL' => "HTTP/{$protocol}",
            'HTTP_HOST' => $hostHeader,
            'REQUEST_TIME_FLOAT' => microtime(true),
            'REQUEST_TIME' => time(),
        ];

        $request = (new ServerRequest($method, $uri, $rawHeaders, $body, $protocol, $serverParams))
            ->withAttribute(Upgrade::ATTRIBUTE, $upgrade);

        $query = parse_url($uri, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $queryParams);
            $request = $request->withQueryParams($queryParams);
        }

        $response = $handler($request);

        if ($upgrade->upgraded) {
            return; // the controller took the connection over (and is done with it)
        }

        self::writeResponse($socket, $response);
    }

    /** @param resource $socket */
    public static function writeResponse(mixed $socket, ResponseInterface $response): void
    {
        $body = (string) $response->getBody();
        $out = sprintf("HTTP/1.1 %d %s\r\n", $response->getStatusCode(), $response->getReasonPhrase());
        foreach ($response->getHeaders() as $name => $values) {
            if (in_array(strtolower((string) $name), ['content-length', 'transfer-encoding', 'connection'], true)) {
                continue;
            }
            foreach ($values as $value) {
                $out .= "{$name}: {$value}\r\n";
            }
        }
        $out .= 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;

        @fwrite($socket, $out);
    }

    /** @param resource $socket */
    private static function writeRaw(mixed $socket, int $status, string $reason): void
    {
        @fwrite($socket, "HTTP/1.1 {$status} {$reason}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }
}
