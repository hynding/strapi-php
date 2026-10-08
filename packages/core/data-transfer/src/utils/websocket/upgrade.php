<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Websocket;

/**
 * Not an upstream file: what a request routed to the transfer server ({@see Server}) carries so a
 * controller can take the connection over (Koa's `ctx.req` / `ctx.request.socket` that
 * `wss.handleUpgrade()` receives upstream). It is the request attribute {@see self::ATTRIBUTE};
 * requests served by FPM/FrankenPHP have none (PHP code cannot hold an upgraded connection there).
 */
final class Upgrade
{
    public const string ATTRIBUTE = 'strapi.data-transfer.websocket';

    public bool $upgraded = false;

    /**
     * @param resource              $socket
     * @param array<string, string> $headers lower-cased request headers
     */
    public function __construct(
        public readonly mixed $socket,
        public readonly array $headers,
        private readonly string $leftover = '',
    ) {
    }

    /** Complete the opening handshake (`101 Switching Protocols`) and return the connection. */
    public function accept(): Connection
    {
        if ($this->upgraded) {
            throw new \LogicException('The connection has already been upgraded');
        }

        $key = $this->headers['sec-websocket-key'] ?? '';
        $version = $this->headers['sec-websocket-version'] ?? '';
        if ($key === '' || base64_decode($key, true) === false || strlen((string) base64_decode($key, true)) !== 16 || $version !== '13') {
            throw new \RuntimeException('Invalid WebSocket upgrade request');
        }

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . Client::acceptKey($key) . "\r\n\r\n";

        fwrite($this->socket, $response);
        $this->upgraded = true;

        return new Connection($this->socket, false, $this->leftover);
    }

    /** Drop the TCP connection (upstream destroys the socket when the upgrade fails). */
    public function destroy(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->upgraded = true;
    }
}
