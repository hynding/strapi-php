<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Remote\Handlers;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Engine\Engine;
use Strapi\DataTransfer\Errors\ProviderError;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\TransferWebsocketJson;
use Strapi\DataTransfer\Utils\Websocket\Connection;
use Strapi\Types\Core\Context;

/**
 * Port of src/strapi/remote/handlers/abstract.ts (the `Handler` interface) together with the
 * prototype `handlerControllerFactory` (handlers/utils.ts) builds for each connection: transfer
 * state, message UUIDs, messaging (`respond`, `send`, `confirm`, `executeAndRespond`),
 * auth verification and the default lifecycles. {@see Push} and {@see Pull} extend it.
 */
abstract class Handler
{
    /** @var string|null Transfer ID */
    public ?string $transferID = null;

    /** @var int|null Started At (ms) */
    public ?int $startedAt = null;

    /** @var array{uuid?: string|null, e?: \Throwable|null, data?: mixed}|null */
    public ?array $response = null;

    public Diagnostic $diagnostics;

    /** @var array<string, true> */
    private array $messageUUIDs = [];

    /**
     * Work to run once the current message has been answered (upstream starts it without awaiting it).
     *
     * @var list<\Closure(): void>
     */
    protected array $deferred = [];

    /**
     * @param \Closure(Context, string|null): void $verify
     */
    public function __construct(
        protected readonly Strapi $strapi,
        protected readonly Context $ctx,
        protected readonly Connection $ws,
        private readonly \Closure $verify,
    ) {
        $this->diagnostics = Diagnostic::createDiagnosticReporter();

        $this->diagnostics->onDiagnostic(function (array $diagnostic): void {
            $uuid = self::randomUUID();
            $payload = TransferWebsocketJson::stringifyTransferWebSocketPayload([
                'diagnostic' => $diagnostic,
                'uuid' => $uuid,
            ]);

            try {
                $this->send($payload);
            } catch (\Throwable) {
                // the connection is gone
            }
        });
    }

    public static function randomUUID(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    protected static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    // Add message UUIDs
    public function addUUID(string $uuid): void
    {
        $this->messageUUIDs[$uuid] = true;
    }

    // Check if a message UUID exists
    public function hasUUID(string $uuid): bool
    {
        return isset($this->messageUUIDs[$uuid]);
    }

    /**
     * Returns whether a transfer is currently in progress or not
     */
    public function isTransferStarted(): bool
    {
        return $this->transferID !== null && $this->startedAt !== null;
    }

    /**
     * Make sure the current transfer is started and initialized
     */
    public function assertValidTransfer(): void
    {
        $isStarted = $this->isTransferStarted();

        if (!$isStarted) {
            throw new \RuntimeException('Invalid Transfer Process');
        }
    }

    /**
     * Checks that the given string is a valid transfer command
     */
    public function assertValidTransferCommand(mixed $command): void
    {
        $isDefined = is_string($command) && method_exists($this, $command);
        $isValidTransferCommand = in_array($command, Constants::VALID_TRANSFER_COMMANDS, true);

        if (!$isDefined || !$isValidTransferCommand) {
            throw new \RuntimeException('Invalid transfer command');
        }
    }

    /**
     * Respond to a specific message
     */
    public function respond(?string $uuid = null, ?\Throwable $e = null, mixed $data = null): void
    {
        if ($uuid === null && $e === null) {
            throw new \RuntimeException('Missing uuid for this message');
        }

        $this->response = [
            'uuid' => $uuid,
            'data' => $data,
            'e' => $e,
        ];

        $details = new \stdClass();
        if ($e instanceof ProviderError) {
            $details = $e->details;
        }

        $envelope = [];
        if ($uuid !== null) {
            $envelope['uuid'] = $uuid;
        }
        $envelope['data'] = $data;
        $envelope['error'] = $e !== null
            ? [
                'code' => Engine::errorName($e),
                'message' => $e->getMessage(),
                'details' => $details,
            ]
            : null;

        $payload = TransferWebsocketJson::stringifyTransferWebSocketPayload($envelope);

        $this->send($payload);
    }

    /**
     * It sends a message to the client
     */
    public function send(string $message): void
    {
        $this->ws->send($message);
    }

    /**
     * It sends a message to the client and waits for a confirmation
     */
    public function confirm(mixed $message): mixed
    {
        $uuid = self::randomUUID();

        $payload = TransferWebsocketJson::stringifyTransferWebSocketPayload(['uuid' => $uuid, 'data' => $message]);

        $this->send($payload);

        $raw = $this->ws->waitFor(static function (string $raw) use ($uuid): bool {
            $response = json_decode($raw, true);

            return is_array($response) && ($response['uuid'] ?? null) === $uuid;
        });

        if ($raw === null) {
            throw new \RuntimeException('WebSocket is not open: readyState 3 (CLOSED)');
        }

        $response = json_decode($raw, true);

        return is_array($response) ? ($response['data'] ?? null) : null;
    }

    /**
     * Invoke a function and return its result to the client
     *
     * @param callable(): mixed $fn
     */
    public function executeAndRespond(string $uuid, callable $fn): void
    {
        try {
            $response = $fn();
            $this->respond($uuid, null, $response);
        } catch (\Throwable $e) {
            try {
                $this->respond($uuid, $e);
            } catch (\Throwable $err) {
                $this->cannotRespondHandler($err);
            }
        }
    }

    /** Close the connection when an error response cannot be sent. */
    public function cannotRespondHandler(\Throwable $err): void
    {
        $this->strapi->log()->error('[Data transfer] Cannot send error response to client, closing connection');
        $this->strapi->log()->error($err->getMessage());
        try {
            $this->ws->terminate();
        } catch (\Throwable) {
            $this->strapi->log()->error('[Data transfer] Failed to close socket on error');
        }
    }

    /**
     * Lifecycle called to cleanup the transfer state
     */
    public function cleanup(): void
    {
        $this->transferID = null;
        $this->startedAt = null;
        $this->response = null;
    }

    /**
     * Lifecycle called on error or when the ws connection is closed
     */
    public function teardown(): void
    {
        $this->cleanup();
    }

    /**
     * Check the current auth has the permission for the given scope
     */
    public function verifyAuth(?string $scope = null): void
    {
        ($this->verify)($this->ctx, $scope);
    }

    /** Run the work queued while handling the last message. */
    public function runDeferred(): void
    {
        while ($this->deferred !== []) {
            $fn = array_shift($this->deferred);
            $fn();
        }
    }

    // Transfer commands
    /** @param array<string, mixed>|null $params */
    public function init(?array $params = null): mixed
    {
        return null;
    }

    /** @param array<string, mixed>|null $params */
    public function end(?array $params = null): mixed
    {
        return null;
    }

    public function status(): mixed
    {
        return null;
    }

    // Default prototype implementation for events
    public function onMessage(string $raw): void
    {
    }

    public function onError(\Throwable $err): void
    {
    }

    public function onClose(int $code = 1005, string $reason = ''): void
    {
    }

    public function onInfo(string $message): void
    {
    }

    public function onWarning(string $message): void
    {
    }

    /** `ProviderTransferError` for a thrown string / unexpected value (executeAndRespond). */
    protected static function toError(mixed $e): \Throwable
    {
        if ($e instanceof \Throwable) {
            return $e;
        }
        if (is_string($e)) {
            return new ProviderTransferError($e);
        }

        return new ProviderTransferError('Unexpected error', ['error' => $e]);
    }
}
