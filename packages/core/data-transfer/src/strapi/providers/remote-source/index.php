<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\RemoteSource;

use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Errors\Providers\ProviderValidationError;
use Strapi\DataTransfer\Strapi\Providers\Utils;
use Strapi\DataTransfer\Strapi\Providers\Utils\Dispatcher;
use Strapi\DataTransfer\Strapi\Remote\Constants;
use Strapi\DataTransfer\Types\Providers\ISourceProvider;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\TransferAssetChunk;
use Strapi\DataTransfer\Utils\TransferWebsocketJson;
use Strapi\DataTransfer\Utils\Websocket\Connection;

/**
 * Port of src/strapi/providers/remote-source/index.ts: `createRemoteStrapiSourceProvider()`, the
 * pull client — reads a remote Strapi (Node or PHP) over `ws(s)://<url>/transfer/runner/pull`.
 *
 * Each stage's data arrives as messages the server sends and waits acknowledgements for; the read
 * streams are generators that read (and acknowledge) them as the engine consumes the stage. An
 * asset's `stream` yields its bytes as they arrive and must be read before the next asset.
 *
 * Options: `url`, `auth`, `retryMessageOptions`, `streamTimeout` (ms without progress on an
 * asset, default 300000), `verifyChecksums`, plus the local source options (`getStrapi`, …).
 *
 * @phpstan-type RemoteSourceOptions array{url: string, auth?: array{type: string, token?: string}|null, retryMessageOptions?: array{retryMessageTimeout: int, retryMessageMaxRetries: int}|null, streamTimeout?: int|null, verifyChecksums?: bool|null, getStrapi?: callable|null, autoDestroy?: bool|null}
 */
final class RemoteSource implements ISourceProvider
{
    /**
     * Pull server answers `assets` step `start` only after `estimateAssetTotals`, which can exceed
     * the default dispatcher wait: this message uses a longer window.
     */
    private const array ASSETS_START_RETRY_OVERRIDES = [
        'retryMessageTimeout' => 120_000,
        'retryMessageMaxRetries' => 30,
    ];

    public string $name = 'source::remote-strapi';

    public string $type = 'source';

    /** @var RemoteSourceOptions */
    public array $options;

    public ?Connection $ws = null;

    public ?Dispatcher $dispatcher = null;

    /** @var array<string, mixed>|null */
    public ?array $results = null;

    /** @var array{streamTimeout: int} */
    public array $defaultOptions = [
        // Large files + JSON/WS backpressure can go minutes between *messages* while bytes still drain locally
        'streamTimeout' => 300_000,
    ];

    private ?Diagnostic $diagnostics = null;

    private bool $pullAssetStreamWireSampleLogged = false;

    private bool $checksumsEnabled = false;

    /** @var array{totalBytes?: int, totalCount?: int}|null set from pull server `start` response for `assets` */
    private ?array $cachedAssetsTotals = null;

    /** @param RemoteSourceOptions $options */
    public function __construct(array $options)
    {
        $this->options = [...$this->defaultOptions, ...$options];
        $this->checksumsEnabled = ($this->options['verifyChecksums'] ?? null) === true;
    }

    /** @param RemoteSourceOptions $options */
    public static function createRemoteStrapiSourceProvider(array $options): self
    {
        return new self($options);
    }

    /**
     * Start a stage and read its items: the server sends `{ uuid, data: { type: 'transfer', id,
     * data, ended, error } }` messages, each acknowledged with `{ uuid }`.
     *
     * @return \Generator<int, mixed>
     */
    private function createStageReadStream(string $stage): \Generator
    {
        if ($stage === 'assets') {
            $this->cachedAssetsTotals = null;
        }

        $startResult = $this->startStep($stage);

        if ($startResult instanceof \Throwable) {
            throw $startResult;
        }

        $processID = is_array($startResult) ? ($startResult['id'] ?? null) : null;
        $totals = is_array($startResult) ? ($startResult['totals'] ?? null) : null;

        if ($stage === 'assets' && is_array($totals) && (isset($totals['totalBytes']) || isset($totals['totalCount']))) {
            $this->cachedAssetsTotals = $totals;
        }

        return $this->readStage($stage, $processID);
    }

    /** @return \Generator<int, mixed> */
    private function readStage(string $stage, mixed $processID): \Generator
    {
        $ws = $this->ws ?? throw new ProviderTransferError('No websocket connection found');
        // assets: no forward progress for `streamTimeout` ms aborts the stage (upstream's stall timer)
        $timeout = $stage === 'assets' ? ((int) ($this->options['streamTimeout'] ?? 300_000)) / 1000 : null;

        while (true) {
            $raw = $ws->waitFor(static function (string $raw) use ($processID): bool {
                $parsed = json_decode($raw, true);

                // If not a message related to our transfer process, ignore it
                return is_array($parsed)
                    && !empty($parsed['uuid'])
                    && ($parsed['data']['type'] ?? null) === 'transfer'
                    && ($parsed['data']['id'] ?? null) === $processID;
            }, $timeout);

            if ($raw === null) {
                if (!$ws->isClosed()) {
                    $this->reportInfo('Asset transfer stalled, aborting.');
                    throw new \RuntimeException('Asset transfer timed out');
                }
                throw new ProviderTransferError('WebSocket is not open: readyState 3 (CLOSED)');
            }

            $parsed = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
            if (!is_array($parsed) || !is_string($parsed['uuid'] ?? null)) {
                continue;
            }
            $uuid = $parsed['uuid'];
            $message = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
            $error = $message['error'] ?? null;

            if ($error !== null && $error !== false) {
                $this->respond($uuid);
                $errorMessage = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : "Error in remote {$stage} stream";
                throw new ProviderTransferError($errorMessage, ['error' => $error]);
            }

            if (!empty($message['ended'])) {
                $this->respond($uuid);
                $this->endStep($stage);

                return;
            }

            $data = $message['data'] ?? null;
            // acknowledge first: the server prepares the next batch while this one is processed
            $this->respond($uuid);

            foreach (is_array($data) && array_is_list($data) ? $data : [$data] as $item) {
                yield $item;
            }
        }
    }

    /** @return \Generator<int, mixed> */
    public function createEntitiesReadStream(): \Generator
    {
        return $this->createStageReadStream('entities');
    }

    /** @return \Generator<int, mixed> */
    public function createLinksReadStream(): \Generator
    {
        return $this->createStageReadStream('links');
    }

    /** @return \Generator<int, mixed> */
    public function createConfigurationReadStream(): \Generator
    {
        return $this->createStageReadStream('configuration');
    }

    /**
     * Assets: `{ filename, filepath, stats, metadata, stream }` where `stream` yields the bytes as
     * they arrive.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function createAssetsReadStream(): \Generator
    {
        // Create the streams used to transfer the assets
        $batches = $this->createStageReadStream('assets');

        return $this->readAssets($batches);
    }

    /**
     * @param \Generator<int, mixed> $batches
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function readAssets(\Generator $batches): \Generator
    {
        $streamTimeout = ((int) ($this->options['streamTimeout'] ?? 300_000)) / 1000;

        // a cursor over the asset flow items (each batch is a list of items)
        $queue = [];
        $next = static function () use ($batches, &$queue): ?array {
            while ($queue === []) {
                if (!$batches->valid()) {
                    return null;
                }
                $payload = $batches->current();
                $batches->next();
                foreach (is_array($payload) && array_is_list($payload) ? $payload : [$payload] as $item) {
                    if (is_array($item)) {
                        $queue[] = $item;
                    }
                }
            }

            return array_shift($queue);
        };
        $pushBack = static function (array $item) use (&$queue): void {
            array_unshift($queue, $item);
        };

        $batches->current(); // start the stage

        while (($item = $next()) !== null) {
            $action = $item['action'] ?? null;
            $assetID = (string) ($item['assetID'] ?? '');

            if ($action !== 'start') {
                throw new \RuntimeException("No id matching {$assetID} for stream action");
            }

            $this->reportInfo("Asset {$assetID} starting");
            $checksumHash = $this->checksumsEnabled ? hash_init('sha256') : null;
            $state = ['done' => false];

            $stream = (function () use ($assetID, $next, $pushBack, $checksumHash, &$state, $streamTimeout): \Generator {
                $lastProgress = microtime(true);
                while (true) {
                    $chunkItem = $next();
                    if ($chunkItem === null) {
                        throw new \RuntimeException("Asset {$assetID} transfer ended before its end chunk");
                    }

                    $chunkAction = $chunkItem['action'] ?? null;
                    $chunkAssetID = (string) ($chunkItem['assetID'] ?? '');

                    if ($chunkAction === 'start') {
                        if ($chunkAssetID === $assetID) {
                            throw new \RuntimeException("Asset {$assetID} already started");
                        }
                        $pushBack($chunkItem);
                        throw new \RuntimeException("Failed to write asset chunk for \"{$chunkAssetID}\". Asset not found.");
                    }

                    if ($chunkAssetID !== $assetID) {
                        throw new \RuntimeException("No id matching {$chunkAssetID} for stream action");
                    }

                    if (microtime(true) - $lastProgress > $streamTimeout) {
                        $this->reportInfo("Asset {$assetID} transfer stalled, aborting.");
                        throw new \RuntimeException("Asset {$assetID} transfer timed out");
                    }

                    if ($chunkAction === 'stream') {
                        if (!$this->pullAssetStreamWireSampleLogged) {
                            $this->pullAssetStreamWireSampleLogged = true;
                            $data = $chunkItem['data'] ?? null;
                            if (is_array($data) && ($data['type'] ?? null) === 'Buffer' && is_array($data['data'] ?? null)) {
                                $this->reportWarning('[Data transfer][pull] Remote is using legacy Buffer JSON for asset chunks (each byte as a JSON number). That uses much more memory during JSON.parse than base64. Upgrade the remote Strapi to a version that sends base64 asset chunks, or out-of-memory errors may still happen on large files.');
                            }
                        }

                        $chunk = TransferAssetChunk::decodeTransferAssetStreamItem($chunkItem);
                        if ($checksumHash !== null) {
                            hash_update($checksumHash, $chunk);
                        }
                        $lastProgress = microtime(true);
                        yield $chunk;
                        continue;
                    }

                    if ($chunkAction === 'end') {
                        $this->reportInfo("Ending asset stream for {$assetID}");
                        $checksum = $chunkItem['checksum'] ?? null;
                        if ($this->checksumsEnabled) {
                            if (!is_array($checksum)) {
                                throw new ProviderTransferError("Asset {$assetID} is missing checksum in transfer end payload");
                            }
                            if (($checksum['algorithm'] ?? null) !== 'sha256') {
                                throw new ProviderTransferError("Asset {$assetID} checksum algorithm \"" . (is_scalar($checksum['algorithm'] ?? null) ? (string) $checksum['algorithm'] : 'undefined') . '" is not supported');
                            }
                            $actual = $checksumHash !== null ? hash_final($checksumHash) : null;
                            if ($actual === null || $actual !== ($checksum['value'] ?? null)) {
                                throw new ProviderTransferError("Checksum mismatch for asset \"{$assetID}\" (expected " . (is_scalar($checksum['value'] ?? null) ? (string) $checksum['value'] : 'undefined') . ', got ' . ($actual ?? 'none') . ')');
                            }
                        }
                        $state['done'] = true;

                        return;
                    }

                    throw new ProviderTransferError('Invalid asset flow action: ' . (is_scalar($chunkAction) ? (string) $chunkAction : 'undefined'));
                }
            })();

            $data = is_array($item['data'] ?? null) ? $item['data'] : [];
            yield [...$data, 'stream' => $stream];

            // the destination did not read the whole asset: skip the rest of it
            if (!$state['done']) {
                foreach ($stream as $_) {
                    // drain
                }
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        $metadata = $this->dispatcher?->dispatchTransferAction('getMetadata');

        return is_array($metadata) ? $metadata : null;
    }

    public function assertValidProtocol(string $protocol): void
    {
        $validProtocols = ['https:', 'http:'];

        if (!in_array($protocol, $validProtocols, true)) {
            throw new ProviderValidationError("Invalid protocol \"{$protocol}\"", [
                'check' => 'url',
                'details' => [
                    'protocol' => $protocol,
                    'validProtocols' => $validProtocols,
                ],
            ]);
        }
    }

    public function initTransfer(): string
    {
        $wantsChecksums = ($this->options['verifyChecksums'] ?? null) === true;
        $res = $this->dispatcher?->dispatchCommand([
            'command' => 'init',
            ...($wantsChecksums ? ['params' => ['transfer' => 'pull', 'checksums' => true]] : []),
        ]);

        if (!is_array($res) || empty($res['transferID'])) {
            throw new ProviderTransferError('Init failed, invalid response from the server');
        }
        $this->checksumsEnabled = $wantsChecksums && ($res['checksums'] ?? null) === true;
        if ($wantsChecksums && ($res['checksums'] ?? null) !== true) {
            $this->reportWarning('[Data transfer][pull] Checksums were requested but the remote does not support checksum negotiation; continuing without checksum validation.');
        }

        return (string) $res['transferID'];
    }

    private function reportInfo(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'remote-source-provider',
            ],
            'kind' => 'info',
        ]);
    }

    /** Reports a warning diagnostic (`kind: 'warning'`). Consumers (e.g. CLI) choose log levels and routing. */
    private function reportWarning(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'remote-source-provider',
            ],
            'kind' => 'warning',
        ]);
    }

    public function bootstrap(?Diagnostic $diagnostics = null): void
    {
        $this->diagnostics = $diagnostics;
        $url = parse_url($this->options['url']);
        $auth = $this->options['auth'] ?? null;
        $protocol = (is_array($url) && isset($url['scheme']) ? strtolower($url['scheme']) : '') . ':';
        $this->assertValidProtocol($protocol);
        if (!is_array($url) || !isset($url['host'])) {
            throw new ProviderValidationError("Invalid protocol \"{$protocol}\"");
        }
        $wsProtocol = $protocol === 'https:' ? 'wss:' : 'ws:';
        $host = $url['host'] . (isset($url['port']) ? ":{$url['port']}" : '');
        $wsUrl = "{$wsProtocol}//{$host}" . Utils::trimTrailingSlash($url['path'] ?? '') . Constants::TRANSFER_PATH . '/pull';

        $this->pullAssetStreamWireSampleLogged = false;

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
        $this->reportInfo("initialized transfer {$transferID}");

        $dispatcher->setTransferProperties(['id' => $transferID, 'kind' => 'pull']);
        $dispatcher->dispatchTransferAction('bootstrap');
    }

    public function close(): void
    {
        $this->dispatcher?->dispatchTransferAction('close');

        // upstream checks `ws.CLOSED` (the constant, always truthy) and never closes the socket
        if ($this->ws !== null && !$this->ws->isClosed()) {
            $this->ws->close();
        }
    }

    /** @return array<string, mixed>|null */
    public function getSchemas(): ?array
    {
        $schemas = $this->dispatcher?->dispatchTransferAction('getSchemas');

        return is_array($schemas) ? $schemas : null;
    }

    /** @return array{totalBytes?: int, totalCount?: int}|null */
    public function getStageTotals(string $stage): ?array
    {
        if ($stage !== 'assets') {
            return null;
        }

        return $this->cachedAssetsTotals;
    }

    private function startStep(string $step): mixed
    {
        try {
            return $this->dispatcher?->dispatchTransferStep(
                ['action' => 'start', 'step' => $step],
                $step === 'assets' ? ['retryOverrides' => self::ASSETS_START_RETRY_OVERRIDES] : []
            );
        } catch (\Throwable $e) {
            return $e;
        }
    }

    private function respond(string $uuid): void
    {
        $this->ws?->send(TransferWebsocketJson::stringifyTransferWebSocketPayload(['uuid' => $uuid]));
    }

    private function endStep(string $step): ?\Throwable
    {
        try {
            $this->dispatcher?->dispatchTransferStep(['action' => 'end', 'step' => $step]);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}
