<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Websocket;

/**
 * Not an upstream file: the WebSocket client (`new WebSocket(address, { headers })` from `ws`):
 * opens `ws://` or `wss://` over a stream socket and performs the RFC 6455 opening handshake.
 */
final class Client
{
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * @param array<string, string> $headers extra request headers (e.g. `Authorization`)
     *
     * @throws UnexpectedResponseError when the server does not switch protocols
     */
    public static function connect(string $address, array $headers = [], float $timeout = 30.0): Connection
    {
        $url = parse_url($address);
        if ($url === false || !isset($url['scheme'], $url['host'])) {
            throw new \InvalidArgumentException("Invalid URL: {$address}");
        }

        $scheme = strtolower($url['scheme']);
        if (!in_array($scheme, ['ws', 'wss'], true)) {
            throw new \InvalidArgumentException("The URL's protocol must be one of \"ws:\", \"wss:\", \"http:\", \"https\", or \"ws+unix:\"");
        }

        $secure = $scheme === 'wss';
        $host = $url['host'];
        $port = $url['port'] ?? ($secure ? 443 : 80);
        $path = ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '');

        $context = stream_context_create([
            'ssl' => [
                'peer_name' => trim($host, '[]'),
                'SNI_enabled' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            ($secure ? 'ssl://' : 'tcp://') . "{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException("connect ECONNREFUSED {$host}:{$port}" . ($errstr !== '' ? " ({$errstr})" : ''));
        }

        stream_set_timeout($socket, (int) ceil($timeout));

        $key = base64_encode(random_bytes(16));
        $hostHeader = $host . (isset($url['port']) ? ":{$url['port']}" : '');

        $lines = [
            "GET {$path} HTTP/1.1",
            "Host: {$hostHeader}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
        ];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n");

        // read the response head
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                fclose($socket);
                throw new \RuntimeException(!empty($meta['timed_out']) ? 'Opening handshake has timed out' : 'socket hang up');
            }
            $head .= $chunk;
            if (strlen($head) > 65536) {
                fclose($socket);
                throw new \RuntimeException('Invalid HTTP response');
            }
        }

        [$headPart, $rest] = explode("\r\n\r\n", $head, 2);
        $responseLines = explode("\r\n", $headPart);
        $statusLine = array_shift($responseLines);

        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', (string) $statusLine, $m) !== 1) {
            fclose($socket);
            throw new \RuntimeException('Invalid HTTP response');
        }
        $status = (int) $m[1];

        $responseHeaders = [];
        foreach ($responseLines as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        if ($status !== 101) {
            $body = $rest;
            $length = isset($responseHeaders['content-length']) ? (int) $responseHeaders['content-length'] : null;
            stream_set_timeout($socket, 2);
            while ($length !== null && strlen($body) < $length) {
                $chunk = fread($socket, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            fclose($socket);
            throw new UnexpectedResponseError($status, $body);
        }

        $expected = base64_encode(sha1($key . self::GUID, true));
        if (($responseHeaders['sec-websocket-accept'] ?? null) !== $expected) {
            fclose($socket);
            throw new \RuntimeException('Invalid Sec-WebSocket-Accept header');
        }

        return new Connection($socket, true, $rest);
    }

    /** `Sec-WebSocket-Accept` for a request key (shared with the server side). */
    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }
}
