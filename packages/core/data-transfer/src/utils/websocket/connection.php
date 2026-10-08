<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Websocket;

/**
 * Not an upstream file: one RFC 6455 WebSocket connection over a PHP stream socket (what upstream
 * gets from the `ws` package). Client connections mask their frames, server ones do not.
 *
 * Text messages are read with {@see self::receive()} or {@see self::waitFor()}; messages read
 * while waiting for another one are kept in an inbox and handed out first. Ping, pong, close and
 * fragmented messages are handled here. `$onMessage` sees every incoming message first (the
 * remote providers report the peer's diagnostics with it) and may swallow it by returning true.
 */
final class Connection
{
    public const int OPCODE_CONTINUATION = 0x0;

    public const int OPCODE_TEXT = 0x1;

    public const int OPCODE_BINARY = 0x2;

    public const int OPCODE_CLOSE = 0x8;

    public const int OPCODE_PING = 0x9;

    public const int OPCODE_PONG = 0xA;

    /** maximum size of one message (frames are reassembled up to this) */
    public const int MAX_PAYLOAD = 512 * 1024 * 1024;

    private string $buffer = '';

    /** @var list<string> */
    private array $inbox = [];

    private bool $closed = false;

    private bool $closeSent = false;

    public ?int $closeCode = null;

    public string $closeReason = '';

    /** @var (\Closure(string): bool|null)|null */
    public ?\Closure $onMessage = null;

    /** @var list<\Closure(int, string): void> */
    private array $closeListeners = [];

    /**
     * @param resource $socket
     * @param string   $leftover bytes read past the HTTP handshake
     */
    public function __construct(private readonly mixed $socket, private readonly bool $isClient, string $leftover = '')
    {
        $this->buffer = $leftover;
        stream_set_blocking($this->socket, true);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @param \Closure(int, string): void $listener */
    public function onClose(\Closure $listener): void
    {
        $this->closeListeners[] = $listener;
    }

    public function send(string $text): void
    {
        $this->sendFrame(self::OPCODE_TEXT, $text);
    }

    public function ping(string $payload = ''): void
    {
        $this->sendFrame(self::OPCODE_PING, $payload);
    }

    /** Start the closing handshake (the peer's close frame is awaited for up to `$timeout` seconds). */
    public function close(int $code = 1000, string $reason = '', float $timeout = 5.0): void
    {
        if ($this->closed) {
            return;
        }

        if (!$this->closeSent) {
            try {
                $this->sendFrame(self::OPCODE_CLOSE, pack('n', $code) . $reason);
            } catch (\Throwable) {
                $this->terminate();

                return;
            }
            $this->closeSent = true;
        }

        $deadline = microtime(true) + $timeout;
        try {
            while (!$this->closed && microtime(true) < $deadline) {
                $message = $this->readMessage($deadline - microtime(true));
                if ($message !== null) {
                    $this->inbox[] = $message;
                }
            }
        } catch (\Throwable) {
            // the peer went away
        }

        $this->terminate();
    }

    /** Drop the TCP connection without a closing handshake. */
    public function terminate(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->markClosed($this->closeCode ?? 1006, $this->closeReason);
    }

    /**
     * Next text message: from the inbox, else from the socket. Null on timeout or once closed.
     */
    public function receive(?float $timeout = null): ?string
    {
        if ($this->inbox !== []) {
            return array_shift($this->inbox);
        }

        return $this->readMessage($timeout);
    }

    /**
     * Wait for the first message matching `$predicate` (inbox first); other messages stay in the
     * inbox in order. Null on timeout or once the connection is closed.
     *
     * @param callable(string): bool $predicate
     */
    public function waitFor(callable $predicate, ?float $timeout = null): ?string
    {
        foreach ($this->inbox as $i => $message) {
            if ($predicate($message)) {
                array_splice($this->inbox, $i, 1);

                return $message;
            }
        }

        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while (!$this->closed) {
            $remaining = $deadline === null ? null : $deadline - microtime(true);
            if ($remaining !== null && $remaining <= 0) {
                return null;
            }

            $message = $this->readMessage($remaining);
            if ($message === null) {
                continue;
            }

            if ($predicate($message)) {
                return $message;
            }

            $this->inbox[] = $message;
        }

        return null;
    }

    /** Messages received but not consumed yet. */
    public function pending(): int
    {
        return count($this->inbox);
    }

    private function markClosed(int $code, string $reason): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closeCode = $code;
        $this->closeReason = $reason;
        foreach ($this->closeListeners as $listener) {
            $listener($code, $reason);
        }
    }

    /**
     * Read frames until a complete text/binary message is available. Returns null on timeout or
     * when the connection is closed.
     */
    private function readMessage(?float $timeout): ?string
    {
        $deadline = $timeout === null ? null : microtime(true) + max(0.0, $timeout);
        $fragments = null;

        while (!$this->closed) {
            $frame = $this->readFrame($deadline);
            if ($frame === null) {
                if ($fragments !== null && !$this->isClosed()) {
                    // keep waiting for the rest of a fragmented message
                    $deadline = null;
                    continue;
                }

                return null;
            }

            [$fin, $opcode, $payload] = $frame;

            switch ($opcode) {
                case self::OPCODE_PING:
                    $this->sendFrame(self::OPCODE_PONG, $payload);
                    continue 2;
                case self::OPCODE_PONG:
                    continue 2;
                case self::OPCODE_CLOSE:
                    $code = strlen($payload) >= 2 ? self::uint16(substr($payload, 0, 2)) : 1005;
                    $reason = (string) substr($payload, 2);
                    // the peer's code and reason stand even if it is already gone when we reply
                    $this->closeCode = $code;
                    $this->closeReason = $reason;
                    if (!$this->closeSent) {
                        try {
                            $this->sendFrame(self::OPCODE_CLOSE, strlen($payload) >= 2 ? substr($payload, 0, 2) : '');
                        } catch (\Throwable) {
                        }
                        $this->closeSent = true;
                    }
                    if (is_resource($this->socket)) {
                        @fclose($this->socket);
                    }
                    $this->markClosed($code, $reason);

                    return null;
                case self::OPCODE_TEXT:
                case self::OPCODE_BINARY:
                    $fragments = $payload;
                    break;
                case self::OPCODE_CONTINUATION:
                    if ($fragments === null) {
                        throw new \RuntimeException('Invalid WebSocket frame: unexpected continuation frame');
                    }
                    $fragments .= $payload;
                    break;
                default:
                    throw new \RuntimeException("Invalid WebSocket frame: invalid opcode {$opcode}");
            }

            if (strlen($fragments) > self::MAX_PAYLOAD) {
                throw new \RuntimeException('Max payload size exceeded');
            }

            if ($fin) {
                $message = $fragments;
                $fragments = null;
                if ($this->onMessage !== null && ($this->onMessage)($message) === true) {
                    continue;
                }

                return $message;
            }
        }

        return null;
    }

    /** big-endian unsigned 16-bit integer */
    private static function uint16(string $bytes): int
    {
        return strlen($bytes) < 2 ? 0 : (ord($bytes[0]) << 8) | ord($bytes[1]);
    }

    /** big-endian unsigned 32-bit integer */
    private static function uint32(string $bytes): int
    {
        return strlen($bytes) < 4 ? 0 : (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
    }

    /** @return array{bool, int, string}|null `[fin, opcode, payload]`, null on timeout */
    private function readFrame(?float $deadline): ?array
    {
        $header = $this->readBytes(2, $deadline);
        if ($header === null) {
            return null;
        }

        $b0 = ord($header[0]);
        $b1 = ord($header[1]);
        $fin = ($b0 & 0x80) !== 0;
        $opcode = $b0 & 0x0f;
        $masked = ($b1 & 0x80) !== 0;
        $length = $b1 & 0x7f;

        // once a frame has started, wait for all of it
        if ($length === 126) {
            $length = self::uint16((string) $this->readBytes(2, null));
        } elseif ($length === 127) {
            $bytes = (string) $this->readBytes(8, null);
            $length = (self::uint32(substr($bytes, 0, 4)) << 32) | self::uint32(substr($bytes, 4, 4));
        }

        if ($length > self::MAX_PAYLOAD) {
            throw new \RuntimeException('Max payload size exceeded');
        }

        $mask = $masked ? (string) $this->readBytes(4, null) : '';
        $payload = $length > 0 ? (string) $this->readBytes($length, null) : '';

        if ($masked) {
            $payload = $payload ^ str_pad('', $length, $mask);
        }

        return [$fin, $opcode, $payload];
    }

    /** Read exactly `$n` bytes; null on timeout (only before any byte of them was read). */
    private function readBytes(int $n, ?float $deadline): ?string
    {
        while (strlen($this->buffer) < $n) {
            if (!is_resource($this->socket) || feof($this->socket)) {
                $this->markClosed($this->closeCode ?? 1006, '');

                return null;
            }

            $wait = $deadline === null ? null : $deadline - microtime(true);
            if ($wait !== null && $wait <= 0) {
                // nothing is consumed from the buffer until the bytes are complete
                return null;
            }

            // TLS streams may hold decrypted bytes stream_select() cannot see: read first
            stream_set_blocking($this->socket, false);
            $chunk = @fread($this->socket, max(8192, $n - strlen($this->buffer)));
            stream_set_blocking($this->socket, true);
            if (is_string($chunk) && $chunk !== '') {
                $this->buffer .= $chunk;
                continue;
            }
            if ($chunk === false || (feof($this->socket))) {
                $this->markClosed($this->closeCode ?? 1006, '');

                return null;
            }

            $read = [$this->socket];
            $write = null;
            $except = null;
            $seconds = $wait === null ? null : (int) floor($wait);
            $micro = $wait === null ? null : (int) (($wait - floor($wait)) * 1_000_000);
            $ready = @stream_select($read, $write, $except, $seconds, $micro);
            if ($ready === false) {
                // interrupted by a signal: try again
                continue;
            }
        }

        $out = substr($this->buffer, 0, $n);
        $this->buffer = (string) substr($this->buffer, $n);

        return $out;
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        if ($this->closed || !is_resource($this->socket)) {
            throw new \RuntimeException('WebSocket is not open: readyState 3 (CLOSED)');
        }

        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);
        $maskBit = $this->isClient ? 0x80 : 0;

        if ($length < 126) {
            $frame .= chr($maskBit | $length);
        } elseif ($length < 65536) {
            $frame .= chr($maskBit | 126) . pack('n', $length);
        } else {
            $frame .= chr($maskBit | 127) . pack('NN', $length >> 32, $length & 0xffffffff);
        }

        if ($this->isClient) {
            $mask = random_bytes(4);
            $frame .= $mask . ($payload ^ str_pad('', $length, $mask));
        } else {
            $frame .= $payload;
        }

        $this->write($frame);
    }

    private function write(string $data): void
    {
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($this->socket, substr($data, $written, 1 << 20));
            if ($n === false || $n === 0) {
                if ($n === 0 && !feof($this->socket)) {
                    $read = null;
                    $writeSet = [$this->socket];
                    $except = null;
                    @stream_select($read, $writeSet, $except, 5);
                    continue;
                }
                $this->markClosed($this->closeCode ?? 1006, $this->closeReason);
                throw new \RuntimeException('WebSocket write failed: the connection was closed');
            }
            $written += $n;
        }
    }
}
