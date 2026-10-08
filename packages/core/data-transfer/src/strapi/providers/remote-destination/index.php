<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\RemoteDestination;

use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Errors\Providers\ProviderValidationError;
use Strapi\DataTransfer\Strapi\Providers\Utils;
use Strapi\DataTransfer\Strapi\Providers\Utils\Dispatcher;
use Strapi\DataTransfer\Strapi\Remote\Constants;
use Strapi\DataTransfer\Strapi\Remote\Handlers\Handler;
use Strapi\DataTransfer\Types\Providers\IDestinationProvider;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Json;
use Strapi\DataTransfer\Utils\Stream\Writable;
use Strapi\DataTransfer\Utils\TransferAssetChunk;
use Strapi\DataTransfer\Utils\Websocket\Connection;

/**
 * Port of src/strapi/providers/remote-destination/index.ts: `createRemoteStrapiDestinationProvider()`,
 * the push client — streams the transfer to a remote Strapi (Node or PHP) over
 * `ws(s)://<url>/transfer/runner/push`.
 *
 * Options: `url` (the remote admin URL, e.g. `http://host:1337/admin`), `auth`
 * (`['type' => 'token', 'token' => string]`), `strategy`, `restore`, `onTransferPhase`,
 * `retryMessageOptions`, `verifyChecksums`.
 *
 * @phpstan-type RemoteDestinationOptions array{url: string, auth?: array{type: string, token?: string}|null, strategy: string, restore?: array<string, mixed>|null, onTransferPhase?: (callable(string): void)|null, retryMessageOptions?: array{retryMessageTimeout: int, retryMessageMaxRetries: int}|null, verifyChecksums?: bool|null}
 */
final class RemoteDestination implements IDestinationProvider
{
    /**
     * Default batching for entities / links / configuration over WebSocket push.
     *
     * Goals: (1) enough payload per round-trip to stay efficient on large transfers,
     * (2) small enough per message that the remote can process and ack without multi-minute stalls,
     * (3) bounded gap between engine progress and the wire (see item cap + age).
     */
    public const int STREAM_STEP_MAX_BATCH_BYTES = 512 * 1024;

    /** Caps parallel work per message and how far UI count can lead the network for tiny rows. */
    public const int STREAM_STEP_MAX_BATCH_ITEMS = 100;

    /**
     * If the first row in the current batch has waited this long, flush before appending more.
     */
    public const int STREAM_STEP_MAX_BATCH_AGE_MS = 450;

    public string $name = 'destination::remote-strapi';

    public string $type = 'destination';

    /** @var RemoteDestinationOptions */
    public array $options;

    public ?Connection $ws = null;

    public ?Dispatcher $dispatcher = null;

    public ?string $transferID = null;

    /** @var array<string, array{count: int}> */
    public array $stats = [];

    /** @var array<string, mixed>|null */
    public ?array $results = null;

    private ?Diagnostic $diagnostics = null;

    private bool $checksumsEnabled = false;

    /** @var array<string, \Throwable|null> the result of each stage's `start` step */
    private array $startedSteps = [];

    /**
     * Wire format for asset chunks. `'base64'` is the compact default introduced in #23479; remotes
     * that predate that PR need the legacy `{ type: 'Buffer', data: number[] }` shape instead.
     */
    private string $assetEncoding = 'base64';

    /** @param RemoteDestinationOptions $options */
    public function __construct(array $options)
    {
        $this->options = $options;
        $this->checksumsEnabled = ($options['verifyChecksums'] ?? null) === true;

        $this->resetStats();
    }

    /** @param RemoteDestinationOptions $options */
    public static function createRemoteStrapiDestinationProvider(array $options): self
    {
        return new self($options);
    }

    private function resetStats(): void
    {
        $this->stats = [
            'assets' => ['count' => 0],
            'entities' => ['count' => 0],
            'links' => ['count' => 0],
            'configuration' => ['count' => 0],
        ];
    }

    public function initTransfer(): string
    {
        $strategy = $this->options['strategy'];
        $restore = $this->options['restore'] ?? null;
        $wantsChecksums = ($this->options['verifyChecksums'] ?? null) === true;

        $options = ['strategy' => $strategy];
        if ($restore !== null) {
            $options['restore'] = $restore;
        }

        $res = $this->dispatcher?->dispatchCommand([
            'command' => 'init',
            'params' => [
                'options' => $options,
                'transfer' => 'push',
                ...($wantsChecksums ? ['checksums' => true] : []),
                'assetEncoding' => 'base64',
            ],
        ]);

        if (!is_array($res) || empty($res['transferID'])) {
            throw new ProviderTransferError('Init failed, invalid response from the server');
        }
        $this->checksumsEnabled = $wantsChecksums && ($res['checksums'] ?? null) === true;
        if ($wantsChecksums && ($res['checksums'] ?? null) !== true) {
            $this->reportWarning('[Data transfer][push] Checksums were requested but the remote does not support checksum negotiation; continuing without checksum validation.');
        }

        // A remote that predates #23479 silently drops the `assetEncoding` field instead of echoing
        // it. Falling back to the legacy Buffer JSON shape keeps small pushes working against those
        // remotes.
        if (($res['assetEncoding'] ?? null) === 'base64') {
            $this->assetEncoding = 'base64';
        } else {
            $this->assetEncoding = 'legacy-buffer-json';
            $this->reportWarning(
                '[Data transfer][push] Remote does not support the compact base64 asset-chunk format; '
                . 'falling back to legacy Buffer JSON. Large files may cause out-of-memory errors on the remote — '
                . 'upgrade the remote Strapi to pick up PR #23479 to use the memory-bounded wire format.'
            );
        }

        $this->resetStats();

        return (string) $res['transferID'];
    }

    /**
     * once(() => this.#startStep(stage)): the first call starts the step, later calls return its result.
     *
     * @return \Closure(): ?\Throwable
     */
    private function startStepOnce(string $stage): \Closure
    {
        unset($this->startedSteps[$stage]);

        return function () use ($stage): ?\Throwable {
            if (!array_key_exists($stage, $this->startedSteps)) {
                $this->startedSteps[$stage] = $this->startStep($stage);
            }

            return $this->startedSteps[$stage];
        };
    }

    private function startStep(string $step): ?\Throwable
    {
        try {
            $this->dispatcher?->dispatchTransferStep(['action' => 'start', 'step' => $step]);
        } catch (\Throwable $e) {
            return $e;
        }

        $this->stats[$step] = ['count' => 0];

        return null;
    }

    /** @return array{stats: array{started: int, finished: int}|null, error: \Throwable|null} */
    private function endStep(string $step): array
    {
        try {
            $res = $this->dispatcher?->dispatchTransferStep([
                'action' => 'end',
                'step' => $step,
            ]);

            $stats = is_array($res) && is_array($res['stats'] ?? null) ? $res['stats'] : null;

            return ['stats' => $stats === null ? null : ['started' => (int) ($stats['started'] ?? 0), 'finished' => (int) ($stats['finished'] ?? 0)], 'error' => null];
        } catch (\Throwable $e) {
            return ['stats' => null, 'error' => $e];
        }
    }

    /** @param list<mixed> $message */
    private function streamStep(string $step, array $message): ?\Throwable
    {
        try {
            if ($step === 'assets') {
                $this->stats[$step]['count'] += count(array_filter($message, static fn (mixed $data): bool => is_array($data) && ($data['action'] ?? null) === 'start'));
            } else {
                $this->stats[$step]['count'] += count($message);
            }

            $this->dispatcher?->dispatchTransferStep(['action' => 'stream', 'step' => $step, 'data' => $message]);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function writeStream(string $step): Writable
    {
        $startTransferOnce = $this->startStepOnce($step);

        return new class ($step, $startTransferOnce, fn (array $payload): ?\Throwable => $this->streamStep($step, $payload), fn (): array => $this->endStep($step), fn (): int => $this->stats[$step]['count']) extends Writable {
            /** @var list<mixed> */
            private array $batch = [];

            private int $batchStartedAt = 0;

            /**
             * @param \Closure(): ?\Throwable                                                              $startTransferOnce
             * @param \Closure(list<mixed>): ?\Throwable                                                   $streamStep
             * @param \Closure(): array{stats: array{started: int, finished: int}|null, error: \Throwable|null} $endStep
             * @param \Closure(): int                                                                      $sentCount
             */
            public function __construct(
                private readonly string $step,
                private readonly \Closure $startTransferOnce,
                private readonly \Closure $streamStep,
                private readonly \Closure $endStep,
                private readonly \Closure $sentCount,
            ) {
                parent::__construct();
            }

            private static function nowMs(): int
            {
                return (int) floor(microtime(true) * 1000);
            }

            private function batchLength(): int
            {
                return strlen(Json::stringify($this->batch));
            }

            private function flushBatch(): ?\Throwable
            {
                if ($this->batch === []) {
                    return null;
                }
                $payload = $this->batch;
                $this->batch = [];
                $this->batchStartedAt = 0;

                return ($this->streamStep)($payload);
            }

            private function shouldFlushBatchAfterPush(): bool
            {
                if ($this->batch === []) {
                    return false;
                }

                return $this->batchLength() >= RemoteDestination::STREAM_STEP_MAX_BATCH_BYTES
                    || count($this->batch) >= RemoteDestination::STREAM_STEP_MAX_BATCH_ITEMS
                    || self::nowMs() - $this->batchStartedAt >= RemoteDestination::STREAM_STEP_MAX_BATCH_AGE_MS;
            }

            protected function doWrite(mixed $chunk): void
            {
                $startError = ($this->startTransferOnce)();
                if ($startError !== null) {
                    throw $startError;
                }

                // Flush a batch that has sat long enough before growing it further (bounded latency).
                if ($this->batch !== [] && self::nowMs() - $this->batchStartedAt >= RemoteDestination::STREAM_STEP_MAX_BATCH_AGE_MS) {
                    $staleError = $this->flushBatch();
                    if ($staleError !== null) {
                        throw $staleError;
                    }
                }

                $this->batch[] = $chunk;
                if (count($this->batch) === 1) {
                    $this->batchStartedAt = self::nowMs();
                }

                if ($this->shouldFlushBatchAfterPush()) {
                    $streamError = $this->flushBatch();
                    if ($streamError !== null) {
                        throw $streamError;
                    }
                }
            }

            protected function doFinal(): void
            {
                if ($this->batch !== []) {
                    $streamError = $this->flushBatch();

                    if ($streamError !== null) {
                        throw $streamError;
                    }
                }
                ['error' => $error, 'stats' => $stats] = ($this->endStep)();

                $count = ($this->sentCount)();

                if ($stats !== null && ($stats['started'] !== $count || $stats['finished'] !== $count)) {
                    throw new \RuntimeException("Data missing: sent {$count} {$this->step}, received {$stats['started']} and saved {$stats['finished']} {$this->step}");
                }

                if ($error !== null) {
                    throw $error;
                }
            }
        };
    }

    private function reportInfo(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'remote-destination-provider',
            ],
            'kind' => 'info',
        ]);
    }

    private function reportWarning(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'remote-destination-provider',
            ],
            'kind' => 'warning',
        ]);
    }

    public function bootstrap(?Diagnostic $diagnostics = null): void
    {
        $this->diagnostics = $diagnostics;
        $url = parse_url($this->options['url']);
        $auth = $this->options['auth'] ?? null;
        $validProtocols = ['https:', 'http:'];
        $protocol = (is_array($url) && isset($url['scheme']) ? strtolower($url['scheme']) : '') . ':';

        if (!in_array($protocol, $validProtocols, true) || !is_array($url) || !isset($url['host'])) {
            throw new ProviderValidationError("Invalid protocol \"{$protocol}\"", [
                'check' => 'url',
                'details' => [
                    'protocol' => $protocol,
                    'validProtocols' => $validProtocols,
                ],
            ]);
        }
        $wsProtocol = $protocol === 'https:' ? 'wss:' : 'ws:';
        $host = $url['host'] . (isset($url['port']) ? ":{$url['port']}" : '');
        $wsUrl = "{$wsProtocol}//{$host}" . Utils::trimTrailingSlash($url['path'] ?? '') . Constants::TRANSFER_PATH . '/push';

        $this->reportInfo('establishing websocket connection');
        // No auth defined, trying public access for transfer
        if ($auth === null) {
            $ws = Utils::connectToWebsocket($wsUrl, null, $this->diagnostics);
        }

        // Common token auth, this should be the main auth method
        elseif (($auth['type'] ?? null) === 'token') {
            $headers = ['Authorization' => 'Bearer ' . ($auth['token'] ?? '')];
            $ws = Utils::connectToWebsocket($wsUrl, ['headers' => $headers], $this->diagnostics);
        }

        // Invalid auth method provided
        else {
            throw new ProviderValidationError('Auth method not available', [
                'check' => 'auth.type',
                'details' => [
                    'auth' => $auth['type'] ?? null,
                ],
            ]);
        }

        $this->reportInfo('established websocket connection');

        $this->ws = $ws;
        $retryMessageOptions = $this->options['retryMessageOptions'] ?? null;

        $this->reportInfo('creating dispatcher');
        $dispatcher = Utils::createDispatcher($ws, $retryMessageOptions, fn (string $message) => $this->reportInfo($message));
        $this->dispatcher = $dispatcher;
        $this->reportInfo('created dispatcher');

        $this->reportInfo('initialize transfer');
        $transferID = $this->initTransfer();
        $this->transferID = $transferID;
        $this->reportInfo("initialized transfer {$transferID}");

        $dispatcher->setTransferProperties(['id' => $transferID, 'kind' => 'push']);

        $dispatcher->dispatchTransferAction('bootstrap');
    }

    public function close(): void
    {
        // Gracefully close the remote transfer process
        if ($this->transferID !== null && $this->dispatcher !== null) {
            $this->dispatcher->dispatchTransferAction('close');

            $this->dispatcher->dispatchCommand([
                'command' => 'end',
                'params' => ['transferID' => $this->transferID],
            ]);
        }

        // upstream checks `ws.CLOSED` (the constant, always truthy) and resolves without closing
        // the socket, which then closes when the process exits; close it properly here
        if ($this->ws !== null && !$this->ws->isClosed()) {
            $this->ws->close();
        }
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        $metadata = $this->dispatcher?->dispatchTransferAction('getMetadata');

        return is_array($metadata) ? $metadata : null;
    }

    public function beforeTransfer(): void
    {
        $onTransferPhase = $this->options['onTransferPhase'] ?? null;
        if ($onTransferPhase !== null) {
            $onTransferPhase('Remote: waiting for server to clear data and prepare destination…');
        }
        $this->dispatcher?->dispatchTransferAction('beforeTransfer');
    }

    public function rollback(?\Throwable $e = null): void
    {
        $this->dispatcher?->dispatchTransferAction('rollback');
    }

    /** @return array<string, mixed>|null */
    public function getSchemas(): ?array
    {
        if ($this->dispatcher === null) {
            return null;
        }

        $schemas = $this->dispatcher->dispatchTransferAction('getSchemas');

        return is_array($schemas) ? $schemas : null;
    }

    public function createEntitiesWriteStream(): Writable
    {
        return $this->writeStream('entities');
    }

    public function createLinksWriteStream(): Writable
    {
        return $this->writeStream('links');
    }

    public function createConfigurationWriteStream(): Writable
    {
        return $this->writeStream('configuration');
    }

    public function createAssetsWriteStream(): Writable
    {
        $encodeAssetChunk = $this->assetEncoding === 'base64'
            ? TransferAssetChunk::createTransferAssetStreamChunk(...)
            : TransferAssetChunk::createTransferAssetStreamChunkLegacy(...);

        return new class ($this->startStepOnce('assets'), fn (array $payload): ?\Throwable => $this->streamStep('assets', $payload), fn (): array => $this->endStep('assets'), $this->checksumsEnabled, $encodeAssetChunk) extends Writable {
            private const int BATCH_SIZE = 1024 * 1024; // 1MB

            /** @var list<array<string, mixed>> */
            private array $batch = [];

            private bool $hasStarted = false;

            /**
             * @param \Closure(): ?\Throwable                                                              $startAssetsTransferOnce
             * @param \Closure(list<array<string, mixed>>): ?\Throwable                                    $streamStep
             * @param \Closure(): array{stats: array{started: int, finished: int}|null, error: \Throwable|null} $endStep
             * @param \Closure(string, string): array<string, mixed>                                       $encodeAssetChunk
             */
            public function __construct(
                private readonly \Closure $startAssetsTransferOnce,
                private readonly \Closure $streamStep,
                private readonly \Closure $endStep,
                private readonly bool $verifyChecksums,
                private readonly \Closure $encodeAssetChunk,
            ) {
                parent::__construct();
            }

            private function batchLength(): int
            {
                $total = 0;
                foreach ($this->batch as $chunk) {
                    $total += TransferAssetChunk::transferAssetStreamChunkByteLength($chunk);
                }

                return $total;
            }

            private function flush(): ?\Throwable
            {
                $streamError = ($this->streamStep)($this->batch);
                $this->batch = [];

                return $streamError;
            }

            /** @param array<string, mixed> $chunk */
            private function safePush(array $chunk): void
            {
                $this->batch[] = $chunk;

                if ($this->batchLength() >= self::BATCH_SIZE) {
                    $streamError = $this->flush();
                    if ($streamError !== null) {
                        throw $streamError;
                    }
                }
            }

            protected function doWrite(mixed $asset): void
            {
                $startError = ($this->startAssetsTransferOnce)();
                if ($startError !== null) {
                    throw $startError;
                }

                $this->hasStarted = true;

                $asset = is_array($asset) ? $asset : [];
                $assetID = Handler::randomUUID();
                $checksumHash = $this->verifyChecksums ? hash_init('sha256') : null;

                $this->safePush([
                    'action' => 'start',
                    'assetID' => $assetID,
                    'data' => [
                        'filename' => $asset['filename'] ?? null,
                        'filepath' => $asset['filepath'] ?? null,
                        'stats' => $asset['stats'] ?? null,
                        'metadata' => $asset['metadata'] ?? null,
                    ],
                ]);

                $stream = $asset['stream'] ?? [];
                foreach (is_iterable($stream) ? $stream : [] as $chunk) {
                    $chunk = is_string($chunk) ? $chunk : '';
                    if ($checksumHash !== null) {
                        hash_update($checksumHash, $chunk);
                    }
                    $this->safePush(($this->encodeAssetChunk)($assetID, $chunk));
                }

                $this->safePush([
                    'action' => 'end',
                    'assetID' => $assetID,
                    ...($checksumHash !== null ? ['checksum' => ['algorithm' => 'sha256', 'value' => hash_final($checksumHash)]] : []),
                ]);
            }

            protected function doFinal(): void
            {
                if ($this->batch !== []) {
                    $this->flush();
                }

                if ($this->hasStarted) {
                    ['error' => $endStepError] = ($this->endStep)();

                    if ($endStepError !== null) {
                        throw $endStepError;
                    }
                }
            }
        };
    }
}
