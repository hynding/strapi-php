<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Remote\Handlers;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Strapi\Providers\LocalDestination\LocalDestination;
use Strapi\DataTransfer\Strapi\Remote\Flows\Flows;
use Strapi\DataTransfer\Strapi\TransferPolicy;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Stream\PassThrough;
use Strapi\DataTransfer\Utils\Stream\Writable;
use Strapi\DataTransfer\Utils\TransferAssetChunk;
use Strapi\DataTransfer\Utils\WritableAsyncWrite;
use Strapi\Types\Core\Context;

/**
 * Port of src/strapi/remote/handlers/push.ts: the server side of `strapi transfer --to`. Writes
 * what the client streams into this instance through a local Strapi destination provider.
 *
 * @phpstan-import-type HandlerOptions from Utils
 */
final class Push extends Handler
{
    public const array VALID_TRANSFER_ACTIONS = [
        'bootstrap',
        'close',
        'rollback',
        'beforeTransfer',
        'getMetadata',
        'getSchemas',
    ];

    private const string TRANSFER_KIND = 'push';

    /**
     * Local Strapi Destination Provider used to write data to the current Strapi instance
     */
    public ?LocalDestination $provider = null;

    /**
     * Holds all the stages' stream for the current transfer handler (one registry per connection)
     *
     * @var array<string, Writable>
     */
    public array $streams = [];

    /** @var array<string, array{started: int, finished: int}> */
    public array $stats = [];

    /**
     * Holds all the transferred assets for the current transfer handler (one registry per connection)
     *
     * @var array<string, array<string, mixed>>
     */
    public array $assets = [];

    /**
     * Incremental checksum state keyed by transfer asset ID (only populated when checksums are enabled).
     *
     * @var array<string, \HashContext>
     */
    public array $assetChecksums = [];

    public bool $checksumsEnabled = false;

    /**
     * Orchestrate and manage the transfer messages' ordering
     */
    public ?Flows $flow = null;

    /** A rejected remote policy violation permanently invalidates this connection's transfer. */
    public bool $terminal = false;

    private bool $aborting = false;

    private bool $aborted = false;

    /**
     * @param HandlerOptions $options
     *
     * @return \Closure(Context): void
     */
    public static function createPushController(array $options): \Closure
    {
        return Utils::handlerControllerFactory(
            static fn (Strapi $strapi, Context $ctx, \Strapi\DataTransfer\Utils\Websocket\Connection $ws, \Closure $verify): Handler => new self($strapi, $ctx, $ws, $verify)
        )($options);
    }

    private static function reportTeardownError(Strapi $strapi, \Throwable $error): void
    {
        $strapi->log()->error('[Data transfer] Failed to clean up push transfer');
        $strapi->log()->error($error->getMessage());
    }

    /**
     * Destroy the stage and asset streams, roll the provider back and close it, then cleanup.
     *
     * @param array{provider: LocalDestination|null, streams: array<string, Writable>, assets: array<string, array<string, mixed>>, cleanup: callable(): void} $teardown
     */
    public static function abortPushTransfer(Strapi $strapi, array $teardown): void
    {
        ['provider' => $provider, 'streams' => $streams, 'assets' => $assets, 'cleanup' => $cleanup] = $teardown;

        try {
            foreach ($streams as $stream) {
                try {
                    $stream->destroy();
                } catch (\Throwable $error) {
                    self::reportTeardownError($strapi, $error);
                }
            }
            foreach ($assets as $asset) {
                try {
                    if (($asset['stream'] ?? null) instanceof Writable) {
                        $asset['stream']->destroy();
                    }
                } catch (\Throwable $error) {
                    self::reportTeardownError($strapi, $error);
                }
            }

            if ($provider !== null) {
                try {
                    $provider->rollback();
                } catch (\Throwable $error) {
                    self::reportTeardownError($strapi, $error);
                } finally {
                    // A local provider only owns Strapi lifecycle restoration after bootstrap.
                    if ($provider->strapi !== null) {
                        try {
                            $provider->close();
                        } catch (\Throwable $error) {
                            self::reportTeardownError($strapi, $error);
                        }
                    }
                }
            }
        } finally {
            try {
                $cleanup();
            } catch (\Throwable $error) {
                self::reportTeardownError($strapi, $error);
            }
        }
    }

    /**
     * @param list<mixed>                         $data
     * @param array{started: int, finished: int}  $stats
     */
    public static function writeValidatedPushStreamBatch(Strapi $strapi, string $stage, array $data, Writable $stream, array &$stats): void
    {
        if ($stage === 'entities') {
            foreach ($data as $entity) {
                TransferPolicy::assertRemoteEntityAllowed($strapi, is_array($entity) ? $entity : []);
            }
        } else {
            foreach ($data as $link) {
                TransferPolicy::assertRemoteLinkAllowed(is_array($link) ? $link : []);
            }
        }

        foreach ($data as $item) {
            ++$stats['started'];
            WritableAsyncWrite::write($stream, $item);
            ++$stats['finished'];
        }
    }

    public function isTransferStarted(): bool
    {
        return parent::isTransferStarted() && $this->provider !== null;
    }

    public function verifyAuth(?string $scope = null): void
    {
        parent::verifyAuth(self::TRANSFER_KIND);
    }

    public function onInfo(string $message): void
    {
        $this->diagnostics->report([
            'details' => [
                'message' => $message,
                'origin' => 'push-handler',
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
                'origin' => 'push-handler',
            ],
            'kind' => 'warning',
        ]);
    }

    public function cleanup(): void
    {
        parent::cleanup();

        $this->streams = [];
        $this->assets = [];
        $this->assetChecksums = [];
        $this->checksumsEnabled = false;

        $this->flow = null;
        $this->provider = null;
    }

    public function abort(bool $terminal = false): void
    {
        if ($terminal) {
            $this->terminal = true;
        }

        if ($this->aborting || $this->aborted) {
            return;
        }

        $this->aborting = true;
        try {
            self::abortPushTransfer($this->strapi, [
                'provider' => $this->provider,
                'streams' => $this->streams,
                'assets' => $this->assets,
                'cleanup' => fn () => $this->cleanup(),
            ]);
        } finally {
            $this->aborting = false;
            $this->aborted = true;
        }
    }

    public function teardown(): void
    {
        $this->abort();
        parent::teardown();
    }

    public function assertValidTransfer(): void
    {
        parent::assertValidTransfer();

        if ($this->provider === null) {
            throw new \RuntimeException('Invalid Transfer Process');
        }
    }

    public function assertValidTransferAction(mixed $action): void
    {
        if (in_array($action, self::VALID_TRANSFER_ACTIONS, true)) {
            return;
        }

        throw new ProviderTransferError('Invalid action provided: "' . (is_scalar($action) ? (string) $action : 'undefined') . '"', [
            'action' => $action,
            // upstream: Object.keys(VALID_TRANSFER_ACTIONS)
            'validActions' => array_map('strval', array_keys(self::VALID_TRANSFER_ACTIONS)),
        ]);
    }

    public function assertValidStreamTransferStep(string $stage): void
    {
        $currentStep = $this->flow?->get();
        $nextStep = ['kind' => 'transfer', 'stage' => $stage];

        if (($currentStep['kind'] ?? null) === 'transfer' && empty($currentStep['locked'])) {
            throw new ProviderTransferError('You need to initialize the transfer stage ([object Object]) before starting to stream data');
        }

        if ($this->flow?->cannot($nextStep)) {
            throw new ProviderTransferError('Invalid stage ([object Object]) provided for the current flow', [
                'step' => $nextStep,
            ]);
        }
    }

    public function createWritableStreamForStep(string $step): void
    {
        $mapper = [
            'entities' => fn (): ?Writable => $this->provider?->createEntitiesWriteStream(),
            'links' => fn (): ?Writable => $this->provider?->createLinksWriteStream(),
            'configuration' => fn (): ?Writable => $this->provider?->createConfigurationWriteStream(),
            'assets' => fn (): ?Writable => $this->provider?->createAssetsWriteStream(),
        ];

        if (!isset($mapper[$step])) {
            throw new \RuntimeException('Invalid transfer step, impossible to create a stream');
        }

        $stream = $mapper[$step]();
        if ($stream !== null) {
            $this->streams[$step] = $stream;
        }
    }

    public function onMessage(string $raw): void
    {
        // frames are processed one at a time (upstream serializes them through a promise queue)
        $this->processMessage($raw);
    }

    /** Process one already-serialized WebSocket frame. */
    public function processMessage(string $raw): void
    {
        $msg = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);

        if (!Utils::isDataTransferMessage($msg)) {
            return;
        }

        /** @var array<string, mixed> $msg */
        $uuid = (string) $msg['uuid'];

        if ($uuid === '') {
            $this->respond(null, new \RuntimeException('Missing uuid in message'));

            return;
        }

        if ($this->terminal) {
            $this->respond($uuid, new ProviderTransferError('Transfer has been terminated'));

            return;
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

    public function lockTransferStep(string $stage): void
    {
        $currentStep = $this->flow?->get();
        $nextStep = ['kind' => 'transfer', 'stage' => $stage];

        if (($currentStep['kind'] ?? null) === 'transfer' && !empty($currentStep['locked'])) {
            throw new ProviderTransferError("It's not possible to start a new transfer stage ({$stage}) while another one is in progress (" . ($currentStep['stage'] ?? '') . ')');
        }

        if ($this->flow?->cannot($nextStep)) {
            throw new ProviderTransferError("Invalid stage ({$stage}) provided for the current flow", [
                'step' => $nextStep,
            ]);
        }

        $this->flow?->set([...$nextStep, 'locked' => true]);
    }

    public function unlockTransferStep(string $stage): void
    {
        $currentStep = $this->flow?->get();
        $nextStep = ['kind' => 'transfer', 'stage' => $stage];

        // Cannot unlock if not locked (aka: started)
        if (($currentStep['kind'] ?? null) === 'transfer' && empty($currentStep['locked'])) {
            throw new ProviderTransferError("You need to initialize the transfer stage ({$stage}) before ending it");
        }

        // Cannot unlock if invalid step provided
        if ($this->flow?->cannot($nextStep)) {
            throw new ProviderTransferError("Invalid stage ({$stage}) provided for the current flow", [
                'step' => $nextStep,
            ]);
        }

        $this->flow?->set([...$nextStep, 'locked' => false]);
    }

    /** @param array<string, mixed> $msg */
    public function onTransferStep(array $msg): mixed
    {
        $stage = (string) ($msg['step'] ?? '');
        $action = $msg['action'] ?? null;

        if ($action === 'start') {
            $this->lockTransferStep($stage);

            if (isset($this->streams[$stage])) {
                throw new \RuntimeException('Stream already created, something went wrong');
            }

            $this->createWritableStreamForStep($stage);

            $this->stats[$stage] = ['started' => 0, 'finished' => 0];

            if ($stage === 'assets') {
                $this->strapi->log()->debug('[Transfer destination] Assets stage started');
            }

            return ['ok' => true];
        }

        if ($action === 'stream') {
            $this->assertValidStreamTransferStep($stage);

            // Stream operation on the current transfer stage
            $stream = $this->streams[$stage] ?? null;

            if ($stream === null) {
                throw new \RuntimeException('You need to init first');
            }

            $data = $msg['data'] ?? null;

            // Assets are nested streams
            if ($stage === 'assets') {
                $this->streamAsset(is_array($data) ? array_values($data) : null);

                return null;
            }

            $items = is_array($data) ? array_values($data) : [];

            try {
                if ($stage === 'entities' || $stage === 'links') {
                    self::writeValidatedPushStreamBatch($this->strapi, $stage, $items, $stream, $this->stats[$stage]);

                    return null;
                }
            } catch (\Throwable $error) {
                // Mark terminal before awaiting rollback so no queued WebSocket frame can commit.
                $this->abort(true);
                throw $error;
            }

            // One objectMode Writable: do not overlap writes.
            $stats = $this->stats[$stage] ?? ['started' => 0, 'finished' => 0];
            try {
                foreach ($items as $item) {
                    ++$stats['started'];
                    WritableAsyncWrite::write($stream, $item);
                    ++$stats['finished'];
                }
            } finally {
                $this->stats[$stage] = $stats;
            }

            return null;
        }

        if ($action === 'end') {
            if ($stage === 'assets') {
                $this->strapi->log()->debug('[Transfer destination] Assets stage ended');
            }
            $this->unlockTransferStep($stage);
            $stream = $this->streams[$stage] ?? null;

            if ($stream !== null && !$stream->closed) {
                $stream->end();
            }

            unset($this->streams[$stage]);

            return ['ok' => true, 'stats' => $this->stats[$stage] ?? null];
        }

        return null;
    }

    /** @param array<string, mixed> $msg */
    public function onTransferAction(array $msg): mixed
    {
        $action = $msg['action'] ?? null;

        $this->assertValidTransferAction($action);
        /** @var string $action */

        $step = ['kind' => 'action', 'action' => $action];
        $isStepRegistered = $this->flow?->has($step);

        if ($isStepRegistered) {
            if ($this->flow?->cannot($step)) {
                throw new ProviderTransferError("Invalid action \"{$action}\" found for the current flow ", [
                    'action' => $action,
                ]);
            }

            $this->flow?->set($step);
        }

        $provider = $this->provider;
        if ($provider === null) {
            return null;
        }

        return match ($action) {
            'bootstrap' => $provider->bootstrap($this->diagnostics),
            'close' => $provider->close(),
            'rollback' => $provider->rollback(),
            'beforeTransfer' => $provider->beforeTransfer(),
            'getMetadata' => $provider->getMetadata(),
            'getSchemas' => $provider->getSchemas(),
            default => null,
        };
    }

    /** @param list<mixed>|null $payload */
    public function streamAsset(?array $payload): void
    {
        $assetsStream = $this->streams['assets'] ?? null;

        // TODO: close the stream upon receiving an 'end' event instead
        if ($payload === null) {
            $assetsStream?->end();

            return;
        }

        foreach ($payload as $item) {
            $item = is_array($item) ? $item : [];
            $action = $item['action'] ?? null;
            $assetID = (string) ($item['assetID'] ?? '');

            if ($assetsStream === null) {
                throw new \RuntimeException('Stream not defined');
            }

            if ($action === 'start') {
                ++$this->stats['assets']['started'];
                $data = is_array($item['data'] ?? null) ? $item['data'] : [];
                $this->assets[$assetID] = [...$data, 'stream' => new PassThrough()];
                if ($this->checksumsEnabled) {
                    $this->assetChecksums[$assetID] = hash_init('sha256');
                }
                $filename = $data['filename'] ?? $assetID;
                $this->strapi->log()->debug("[Transfer destination] Asset start #{$this->stats['assets']['started']} id={$assetID} filename={$filename}");
                // Wait for the assets stage to accept this row (same pattern as remote-source).
                WritableAsyncWrite::write($assetsStream, $this->assets[$assetID]);
            } elseif ($action === 'stream' || $action === 'end') {
                if (!isset($this->assets[$assetID])) {
                    throw new ProviderTransferError("No asset \"{$assetID}\" for {$action} action; send start before stream/end");
                }

                /** @var PassThrough $assetStream */
                $assetStream = $this->assets[$assetID]['stream'];

                if ($action === 'stream') {
                    $chunk = TransferAssetChunk::decodeTransferAssetStreamItem($item);
                    if (isset($this->assetChecksums[$assetID])) {
                        hash_update($this->assetChecksums[$assetID], $chunk);
                    }
                    WritableAsyncWrite::write($assetStream, $chunk);
                } else {
                    if ($this->checksumsEnabled) {
                        $checksum = $item['checksum'] ?? null;
                        if (!is_array($checksum)) {
                            throw new ProviderTransferError("Missing checksum for asset \"{$assetID}\"");
                        }
                        if (($checksum['algorithm'] ?? null) !== 'sha256') {
                            throw new ProviderTransferError('Unsupported checksum algorithm "' . (is_scalar($checksum['algorithm'] ?? null) ? (string) $checksum['algorithm'] : 'undefined') . "\" for asset {$assetID}");
                        }
                        $actual = isset($this->assetChecksums[$assetID]) ? hash_final($this->assetChecksums[$assetID]) : null;
                        unset($this->assetChecksums[$assetID]);
                        if ($actual === null || $actual !== ($checksum['value'] ?? null)) {
                            throw new ProviderTransferError("Checksum mismatch for asset \"{$assetID}\" (expected " . (is_scalar($checksum['value'] ?? null) ? (string) $checksum['value'] : 'undefined') . ', got ' . ($actual ?? 'none') . ')');
                        }
                    }
                    unset($this->assetChecksums[$assetID]);
                    $finished = $this->stats['assets']['finished'] + 1;
                    $this->strapi->log()->debug("[Transfer destination] Asset end id={$assetID} (finished={$finished}/{$this->stats['assets']['started']})");

                    $assetStream->end();
                    ++$this->stats['assets']['finished'];
                    unset($this->assets[$assetID]);
                }
            } else {
                throw new ProviderTransferError('Invalid asset flow action: ' . (is_scalar($action) ? (string) $action : 'undefined'));
            }
        }
    }

    public function onClose(int $code = 1005, string $reason = ''): void
    {
        $this->teardown();
    }

    public function onError(\Throwable $err): void
    {
        $this->teardown();
        $this->strapi->log()->error($err->getMessage());
    }

    // Commands

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array{transferID: string, checksums: true, assetEncoding?: 'base64'}
     */
    public function init(?array $params = null): array
    {
        if ($this->transferID !== null || $this->provider !== null) {
            throw new \RuntimeException('Transfer already in progress');
        }

        $this->verifyAuth();

        $this->transferID = self::randomUUID();
        $this->startedAt = self::nowMs();
        $this->aborted = false;
        $this->terminal = false;

        $this->assets = [];
        $this->assetChecksums = [];
        $this->checksumsEnabled = ($params['checksums'] ?? null) === true;
        $this->streams = [];
        $this->stats = [
            'assets' => ['started' => 0, 'finished' => 0],
            'configuration' => ['started' => 0, 'finished' => 0],
            'entities' => ['started' => 0, 'finished' => 0],
            'links' => ['started' => 0, 'finished' => 0],
        ];

        $this->flow = Flows::createFlow(Flows::defaultTransferFlow());

        $options = is_array($params['options'] ?? null) ? $params['options'] : [];
        $strapi = $this->strapi;

        $this->provider = LocalDestination::createLocalStrapiDestinationProvider([
            'strategy' => is_string($options['strategy'] ?? null) ? $options['strategy'] : 'restore',
            'restore' => TransferPolicy::normalizeRemoteRestoreOptions($strapi, is_array($options['restore'] ?? null) ? $options['restore'] : []),
            'autoDestroy' => false,
            'getStrapi' => static fn (): Strapi => $strapi,
        ]);

        $this->provider->onWarning = function (string $message): void {
            $this->onWarning($message);
            $this->strapi->log()->warning($message);
        };

        return [
            'transferID' => $this->transferID,
            'checksums' => true,
            // Echo the client's requested asset wire format so it knows we can decode it. Older remotes
            // (pre-#23479) do `Buffer.from(item.data.data)` directly and will not echo this back — the
            // client treats the missing field as "base64 unsupported" and falls back to legacy shape.
            ...(($params['assetEncoding'] ?? null) === 'base64' ? ['assetEncoding' => 'base64'] : []),
        ];
    }

    /** @return array{active: bool, kind: string|null, startedAt: int|null, elapsed: int|null} */
    public function status(): array
    {
        $isStarted = $this->isTransferStarted();

        if ($isStarted) {
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
}
