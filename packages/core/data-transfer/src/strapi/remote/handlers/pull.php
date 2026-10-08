<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Remote\Handlers;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Strapi\Providers\LocalSource\EstimateAssetTotals;
use Strapi\DataTransfer\Strapi\Providers\LocalSource\LocalSource;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Json;
use Strapi\DataTransfer\Utils\TransferAssetChunk;
use Strapi\DataTransfer\Utils\Websocket\Connection;
use Strapi\Types\Core\Context;

/**
 * Port of src/strapi/remote/handlers/pull.ts: the server side of `strapi transfer --from`. Streams
 * this instance's data to the client through a local Strapi source provider.
 *
 * Upstream starts flushing a stage without awaiting it once the `start` step is answered; here the
 * flush runs right after the answer is sent (the connection is served by one process), and the
 * client's acknowledgements are read while flushing.
 *
 * @phpstan-import-type HandlerOptions from Utils
 */
final class Pull extends Handler
{
    private const string TRANSFER_KIND = 'pull';

    /** Pause between the `start` response and the first streamed batch (PHP-only, see onTransferStep). */
    private const int FLUSH_START_DELAY_MS = 50;

    public const array VALID_TRANSFER_ACTIONS = ['bootstrap', 'close', 'getMetadata', 'getSchemas'];

    public ?LocalSource $provider = null;

    /** @var array<string, iterable<mixed>> */
    public array $streams = [];

    public bool $checksumsEnabled = false;

    /**
     * @param HandlerOptions $options
     *
     * @return \Closure(Context): void
     */
    public static function createPullController(array $options): \Closure
    {
        return Utils::handlerControllerFactory(
            static fn (Strapi $strapi, Context $ctx, Connection $ws, \Closure $verify): Handler => new self($strapi, $ctx, $ws, $verify)
        )($options);
    }

    public function isTransferStarted(): bool
    {
        return parent::isTransferStarted() && $this->provider !== null;
    }

    public function verifyAuth(?string $scope = null): void
    {
        parent::verifyAuth(self::TRANSFER_KIND);
    }

    public function cleanup(): void
    {
        parent::cleanup();

        $this->streams = [];
        $this->checksumsEnabled = false;

        $this->provider = null;
    }

    public function onInfo(string $message): void
    {
        $this->diagnostics->report([
            'details' => [
                'message' => $message,
                'origin' => 'pull-handler',
                'createdAt' => Diagnostic::now(),
            ],
            'kind' => 'info',
        ]);
    }

    public function onWarning(string $message): void
    {
        $this->diagnostics->report([
            'details' => [
                'message' => $message,
                'createdAt' => Diagnostic::now(),
                'origin' => 'pull-handler',
            ],
            'kind' => 'warning',
        ]);
    }

    public function onError(\Throwable $error): void
    {
        $this->diagnostics->report([
            'details' => [
                'message' => $error->getMessage(),
                'error' => $error,
                'createdAt' => Diagnostic::now(),
                'name' => 'Error',
                'severity' => 'fatal',
            ],
            'kind' => 'error',
        ]);
    }

    public function assertValidTransferAction(mixed $action): void
    {
        // Abstract the constant to string[] to allow looser check on the given action
        if (in_array($action, self::VALID_TRANSFER_ACTIONS, true)) {
            return;
        }

        throw new ProviderTransferError('Invalid action provided: "' . (is_scalar($action) ? (string) $action : 'undefined') . '"', [
            'action' => $action,
            'validActions' => array_map('strval', array_keys(self::VALID_TRANSFER_ACTIONS)),
        ]);
    }

    public function onMessage(string $raw): void
    {
        $msg = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);

        if (!Utils::isDataTransferMessage($msg)) {
            return;
        }

        /** @var array<string, mixed> $msg */
        $uuid = (string) $msg['uuid'];

        if ($uuid === '') {
            $this->respond(null, new \RuntimeException('Missing uuid in message'));
        }

        if ($this->hasUUID($uuid)) {
            $previousResponse = $this->response;
            if (($previousResponse['uuid'] ?? null) === $uuid) {
                $this->respond($previousResponse['uuid'] ?? null, $previousResponse['e'] ?? null, $previousResponse['data'] ?? null);
            }

            return;
        }

        $type = $msg['type'];
        $this->addUUID($uuid);
        // Regular command message (init, end, status)
        if ($type === 'command') {
            $command = $msg['command'] ?? null;
            $this->onInfo('received command:' . (is_scalar($command) ? (string) $command : 'undefined') . " uuid:{$uuid}");
            $this->executeAndRespond($uuid, function () use ($command, $msg): mixed {
                $this->assertValidTransferCommand($command);

                // The status command don't have params
                if ($command === 'status') {
                    return $this->status();
                }

                $params = is_array($msg['params'] ?? null) ? $msg['params'] : null;

                return $command === 'init' ? $this->init($params) : $this->end($params);
            });
        }

        // Transfer message (the transfer must be init first)
        elseif ($type === 'transfer') {
            $action = $msg['action'] ?? null;
            $kind = $msg['kind'] ?? null;
            $this->onInfo('received transfer action:' . (is_scalar($action) ? (string) $action : 'undefined') . ' step:' . (is_scalar($kind) ? (string) $kind : 'undefined') . " uuid:{$uuid}");
            $this->executeAndRespond($uuid, function () use ($msg): mixed {
                $this->verifyAuth();

                $this->assertValidTransfer();

                return $this->onTransferMessage($msg);
            });
        }

        // Invalid messages
        else {
            $this->respond($uuid, new \RuntimeException('Bad Request'));
        }
    }

    /** @param array<string, mixed> $msg */
    public function onTransferMessage(array $msg): mixed
    {
        $kind = $msg['kind'] ?? null;

        if ($kind === 'action') {
            return $this->onTransferAction($msg);
        }

        if ($kind === 'step') {
            return $this->onTransferStep($msg);
        }

        return null;
    }

    /** @param array<string, mixed> $msg */
    public function onTransferAction(array $msg): mixed
    {
        $action = $msg['action'] ?? null;

        $this->assertValidTransferAction($action);

        $provider = $this->provider;
        if ($provider === null) {
            return null;
        }

        return match ($action) {
            'bootstrap' => $provider->bootstrap($this->diagnostics),
            'close' => $provider->close(),
            'getMetadata' => $provider->getMetadata(),
            'getSchemas' => $provider->getSchemas(),
            default => null,
        };
    }

    public function flush(string $stage, string $id): void
    {
        $batchSize = 1024 * 1024;
        $batch = [];
        $stream = $this->streams[$stage] ?? null;

        $sendBatch = function (array $batch) use ($id): void {
            $this->confirm([
                'type' => 'transfer',
                'data' => $batch,
                'ended' => false,
                'error' => null,
                'id' => $id,
            ]);
        };

        try {
            if ($stream === null) {
                throw new ProviderTransferError("No available stream found for {$stage}");
            }

            foreach ($stream as $chunk) {
                if ($stage !== 'assets') {
                    $batch[] = $chunk;
                    if (strlen(Json::stringify($batch)) >= $batchSize) {
                        $sendBatch($batch);
                        $batch = [];
                    }
                } else {
                    $this->confirm([
                        'type' => 'transfer',
                        'data' => [$chunk],
                        'ended' => false,
                        'error' => null,
                        'id' => $id,
                    ]);
                }
            }

            if ($batch !== [] && $stage !== 'assets') {
                $sendBatch($batch);
            }
            $this->confirm(['type' => 'transfer', 'data' => null, 'ended' => true, 'error' => null, 'id' => $id]);
        } catch (\Throwable $e) {
            // TODO: if this confirm fails, can we abort the whole transfer?
            try {
                $this->confirm(['type' => 'transfer', 'data' => null, 'ended' => true, 'error' => $e, 'id' => $id]);
            } catch (\Throwable $error) {
                // Handle the error, log it, or take other appropriate actions
                $this->strapi->log()->error("[Data transfer] Message confirmation failed: {$error->getMessage()}");
                $this->onError($error);
            }
        }
    }

    /** @param list<array<string, mixed>> $batch */
    private static function assetBatchLength(array $batch): int
    {
        $total = 0;
        foreach ($batch as $chunk) {
            $total += TransferAssetChunk::transferAssetStreamChunkByteLength($chunk);
        }

        return $total;
    }

    /** @param array<string, mixed> $msg */
    public function onTransferStep(array $msg): mixed
    {
        $step = (string) ($msg['step'] ?? '');
        $action = $msg['action'] ?? null;

        if ($action === 'start') {
            if (isset($this->streams[$step])) {
                throw new \RuntimeException('Stream already created, something went wrong');
            }

            $flushUUID = self::randomUUID();

            $totals = null;
            if ($step === 'assets') {
                $totals = EstimateAssetTotals::estimateAssetTotals($this->strapi);
            }
            $this->createReadableStreamForStep($step);

            $this->deferred[] = function () use ($step, $flushUUID): void {
                // Upstream starts the flush on a later tick, after a DB round trip, so the first
                // batch never shares a TCP read with the `start` response. The Node remote source
                // only listens for batches once that response has resolved (`ws.once` after an
                // await) and `ws` emits all frames of one read synchronously, so a batch that
                // arrives together with the response is dropped and the transfer hangs.
                usleep(self::FLUSH_START_DELAY_MS * 1000);

                try {
                    $this->flush($step, $flushUUID);
                } catch (\Throwable $err) {
                    $this->onError($err);
                }
            };

            return [
                'ok' => true,
                'id' => $flushUUID,
                ...($totals !== null ? ['totals' => $totals] : []),
            ];
        }

        if ($action === 'end') {
            unset($this->streams[$step]);

            return ['ok' => true];
        }

        return null;
    }

    public function createReadableStreamForStep(string $step): void
    {
        $mapper = [
            'entities' => fn (): ?iterable => $this->provider?->createEntitiesReadStream(),
            'links' => fn (): ?iterable => $this->provider?->createLinksReadStream(),
            'configuration' => fn (): ?iterable => $this->provider?->createConfigurationReadStream(),
            'assets' => function (): iterable {
                $assets = $this->provider?->createAssetsReadStream();
                $checksumsEnabled = $this->checksumsEnabled;

                if ($assets === null) {
                    throw new \RuntimeException('Assets read stream could not be created');
                }

                // Generates batches of 1MB of data from the assets stream to avoid sending too many small chunks
                return (static function () use ($assets, $checksumsEnabled): \Generator {
                    $batchMaxSize = 1024 * 1024; // 1MB
                    $batch = [];

                    foreach ($assets as $chunk) {
                        $assetStream = $chunk['stream'] ?? [];
                        unset($chunk['stream']);
                        $assetData = $chunk;

                        // Start the transfer of a new asset
                        $assetID = self::randomUUID();
                        $assetChecksum = $checksumsEnabled ? hash_init('sha256') : null;
                        $batch[] = ['action' => 'start', 'assetID' => $assetID, 'data' => $assetData];

                        foreach (is_iterable($assetStream) ? $assetStream : [] as $assetChunk) {
                            $assetChunk = (string) $assetChunk;
                            if ($assetChecksum !== null) {
                                hash_update($assetChecksum, $assetChunk);
                            }
                            $batch[] = TransferAssetChunk::createTransferAssetStreamChunk($assetID, $assetChunk);

                            // if the batch size is bigger than BATCH_MAX_SIZE stream the batch
                            if (self::assetBatchLength($batch) >= $batchMaxSize) {
                                yield $batch;
                                $batch = [];
                            }
                        }

                        // All the asset data has been streamed and gets ready for the next one
                        $batch[] = [
                            'action' => 'end',
                            'assetID' => $assetID,
                            ...($assetChecksum !== null ? ['checksum' => ['algorithm' => 'sha256', 'value' => hash_final($assetChecksum)]] : []),
                        ];
                        yield $batch;
                        $batch = [];
                    }
                })();
            },
        ];

        if (!isset($mapper[$step])) {
            throw new \RuntimeException('Invalid transfer step, impossible to create a stream');
        }

        $stream = $mapper[$step]();
        if ($stream !== null) {
            $this->streams[$step] = $stream;
        }
    }

    // Commands

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array{transferID: string, checksums: true}
     */
    public function init(?array $params = null): array
    {
        if ($this->transferID !== null || $this->provider !== null) {
            throw new \RuntimeException('Transfer already in progress');
        }
        $this->verifyAuth();

        $this->transferID = self::randomUUID();
        $this->startedAt = self::nowMs();
        $this->checksumsEnabled = ($params['checksums'] ?? null) === true;

        $this->streams = [];

        $strapi = $this->strapi;
        $this->provider = LocalSource::createLocalStrapiSourceProvider([
            'autoDestroy' => false,
            'getStrapi' => static fn (): Strapi => $strapi,
        ]);

        return ['transferID' => $this->transferID, 'checksums' => true];
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array{ok: true}
     */
    public function end(?array $params = null): array
    {
        $this->verifyAuth();

        if ($this->transferID !== ($params['transferID'] ?? null)) {
            throw new ProviderTransferError('Bad transfer ID provided');
        }

        $this->cleanup();

        return ['ok' => true];
    }

    /** @return array{active: bool, kind: string|null, startedAt: int|null, elapsed: int|null} */
    public function status(): array
    {
        $isStarted = $this->isTransferStarted();

        // upstream's condition is inverted: a pull handler reports `active` when it is not started
        if (!$isStarted) {
            $startedAt = (int) $this->startedAt;

            return [
                'active' => true,
                'kind' => self::TRANSFER_KIND,
                'startedAt' => $startedAt,
                'elapsed' => self::nowMs() - $startedAt,
            ];
        }

        return ['active' => false, 'kind' => null, 'elapsed' => null, 'startedAt' => null];
    }
}
