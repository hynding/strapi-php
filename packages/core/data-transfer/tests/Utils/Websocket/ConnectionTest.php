<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Utils\Websocket;

use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\Utils\Websocket\Connection;

/**
 * Not an upstream test (upstream uses the `ws` package): RFC 6455 framing of the PHP WebSocket
 * connection the transfer client and server share, over a local socket pair.
 */
final class ConnectionTest extends TestCase
{
    /** @return array{Connection, Connection} client, server */
    private static function pair(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);

        return [new Connection($sockets[0], true), new Connection($sockets[1], false)];
    }

    public function testExchangesTextMessagesOfEveryLengthEncoding(): void
    {
        [$client, $server] = self::pair();

        foreach (['', 'hello', str_repeat('a', 125), str_repeat('é', 200), str_repeat('x', 70000)] as $message) {
            $client->send($message);
            self::assertSame($message, $server->receive(1.0));

            $server->send($message);
            self::assertSame($message, $client->receive(1.0));
        }
    }

    public function testClientFramesAreMaskedAndServerFramesAreNot(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        [$a, $b] = $sockets;

        (new Connection($a, true))->send('hi');
        $frame = (string) fread($b, 64);
        self::assertSame(0x81, ord($frame[0]));
        self::assertSame(0x80 | 2, ord($frame[1]), 'mask bit and length');
        self::assertSame(8, strlen($frame), '2 header bytes, 4 mask bytes, 2 payload bytes');

        (new Connection($b, false))->send('hi');
        self::assertSame("\x81\x02hi", (string) fread($a, 64));
    }

    public function testWaitForKeepsOtherMessagesInOrder(): void
    {
        [$client, $server] = self::pair();
        $server->send('{"uuid":"a"}');
        $server->send('{"uuid":"b"}');
        $server->send('{"uuid":"c"}');

        self::assertSame('{"uuid":"c"}', $client->waitFor(static fn (string $m): bool => str_contains($m, '"c"'), 1.0));
        self::assertSame(2, $client->pending());
        self::assertSame('{"uuid":"a"}', $client->receive());
        self::assertSame('{"uuid":"b"}', $client->receive());
    }

    public function testCloseHandshakeCarriesCodeAndReason(): void
    {
        [$client, $server] = self::pair();
        $codes = [];
        $client->onClose(static function (int $code, string $reason) use (&$codes): void {
            $codes[] = [$code, $reason];
        });

        $server->close(4001, 'Transfer terminated', 0.0);

        self::assertNull($client->receive(1.0));
        self::assertTrue($client->isClosed());
        self::assertSame(4001, $client->closeCode);
        self::assertSame('Transfer terminated', $client->closeReason);
        self::assertSame([[4001, 'Transfer terminated']], $codes);
    }

    public function testReceiveTimesOutWithoutData(): void
    {
        [$client, $server] = self::pair();

        $start = microtime(true);
        self::assertNull($client->receive(0.05));
        self::assertLessThan(1.0, microtime(true) - $start);
        self::assertFalse($client->isClosed());
        self::assertFalse($server->isClosed());
    }
}
