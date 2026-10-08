<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\Utils;

use Strapi\DataTransfer\Errors\ProviderError;
use Strapi\DataTransfer\Errors\Providers\ProviderInitializationError;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Errors\Providers\ProviderValidationError;
use Strapi\DataTransfer\Strapi\Remote\Handlers\Handler;
use Strapi\DataTransfer\Utils\TransferWebsocketJson;
use Strapi\DataTransfer\Utils\Websocket\Connection;

/**
 * Not an upstream file on its own: the object `createDispatcher(ws, retryMessageOptions, reportInfo)`
 * (src/strapi/providers/utils.ts) returns. A message is sent, then re-sent every
 * `retryMessageTimeout` ms (up to `retryMessageMaxRetries` times) until the response with its
 * UUID arrives; other messages stay queued on the connection.
 *
 * @phpstan-type RetryMessageOptions array{retryMessageMaxRetries: int, retryMessageTimeout: int|float}
 */
final class Dispatcher
{
    /** @var array{kind: string, id: string}|null */
    private ?array $transfer = null;

    /**
     * @param RetryMessageOptions               $retryMessageOptions
     * @param (\Closure(string): void)|null     $reportInfo
     */
    public function __construct(
        private readonly Connection $ws,
        private readonly array $retryMessageOptions = ['retryMessageMaxRetries' => 5, 'retryMessageTimeout' => 30000],
        private readonly ?\Closure $reportInfo = null,
    ) {
    }

    public function transferID(): ?string
    {
        return $this->transfer['id'] ?? null;
    }

    public function transferKind(): ?string
    {
        return $this->transfer['kind'] ?? null;
    }

    /** @param array{kind: string, id: string} $properties */
    public function setTransferProperties(array $properties): void
    {
        $this->transfer = $properties;
    }

    private function info(string $message): void
    {
        if ($this->reportInfo !== null) {
            ($this->reportInfo)($message);
        }
    }

    /**
     * @param array<string, mixed> $message
     * @param array{attachTransfer?: bool, retryOverrides?: array{retryMessageMaxRetries?: int, retryMessageTimeout?: int|float}} $options
     */
    public function dispatch(array $message, array $options = []): mixed
    {
        $uuid = Handler::randomUUID();
        $payload = [...$message, 'uuid' => $uuid];
        $numberOfTimesMessageWasSent = 0;

        if (!empty($options['attachTransfer'])) {
            $payload['transferID'] = $this->transfer['id'] ?? null;
        }

        $describe = static function (array $message): string {
            if (($message['type'] ?? null) === 'command') {
                return 'command:' . ($message['command'] ?? '');
            }

            return 'action:' . ($message['action'] ?? '') . ' ' . (($message['kind'] ?? null) === 'step' ? 'step:' . ($message['step'] ?? '') : '');
        };

        $this->info("dispatching message {$describe($message)} uuid:{$uuid} sent:{$numberOfTimesMessageWasSent}");

        $stringifiedPayload = TransferWebsocketJson::stringifyTransferWebSocketPayload($payload);
        $this->ws->send($stringifiedPayload);

        $retry = [...$this->retryMessageOptions, ...($options['retryOverrides'] ?? [])];
        $retryMessageMaxRetries = (int) $retry['retryMessageMaxRetries'];
        $retryMessageTimeout = ((float) $retry['retryMessageTimeout']) / 1000;

        while (true) {
            $raw = $this->ws->waitFor(static function (string $raw) use ($uuid): bool {
                $response = json_decode($raw, true);

                return is_array($response) && ($response['uuid'] ?? null) === $uuid;
            }, $retryMessageTimeout);

            if ($raw !== null) {
                break;
            }

            if ($this->ws->isClosed()) {
                throw new ProviderTransferError('WebSocket is not open: readyState 3 (CLOSED)');
            }

            if ($numberOfTimesMessageWasSent <= $retryMessageMaxRetries) {
                ++$numberOfTimesMessageWasSent;
                $this->ws->send($stringifiedPayload);
            } else {
                throw new ProviderError('error', 'Request timed out');
            }
        }

        $this->info("received response to message {$describe($message)} uuid:{$uuid} sent:{$numberOfTimesMessageWasSent}");

        $response = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($response)) {
            $response = [];
        }

        if (!empty($response['error'])) {
            $error = is_array($response['error']) ? $response['error'] : [];
            $errorMessage = is_string($error['message'] ?? null) ? $error['message'] : '';
            $errorDetails = is_array($error['details'] ?? null) ? $error['details'] : [];
            $details = $errorDetails['details'] ?? null;
            $step = $errorDetails['step'] ?? null;

            throw match ($step) {
                'transfer' => new ProviderTransferError($errorMessage, $details),
                'validation' => new ProviderValidationError($errorMessage, $details),
                'initialization' => new ProviderInitializationError($errorMessage),
                default => new ProviderError('error', $errorMessage, $details),
            };
        }

        return $response['data'] ?? null;
    }

    /** @param array<string, mixed> $payload `{ command, params? }` */
    public function dispatchCommand(array $payload): mixed
    {
        return $this->dispatch(['type' => 'command', ...$payload]);
    }

    public function dispatchTransferAction(string $action): mixed
    {
        $payload = ['type' => 'transfer', 'kind' => 'action', 'action' => $action];

        return $this->dispatch($payload, ['attachTransfer' => true]);
    }

    /**
     * @param array<string, mixed> $payload `{ step, action, data? }`
     * @param array{retryOverrides?: array{retryMessageMaxRetries?: int, retryMessageTimeout?: int|float}} $dispatchOptions
     */
    public function dispatchTransferStep(array $payload, array $dispatchOptions = []): mixed
    {
        $message = [
            'type' => 'transfer',
            'kind' => 'step',
            ...$payload,
        ];

        return $this->dispatch($message, ['attachTransfer' => true, ...$dispatchOptions]);
    }
}
