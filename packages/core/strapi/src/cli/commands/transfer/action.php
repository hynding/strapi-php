<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Transfer;

use Strapi\Cli\Cli\Utils\DataTransfer;
use Strapi\Cli\Cli\Utils\ExitError;
use Strapi\Cli\Cli\Utils\Helpers;
use Strapi\Cli\Cli\Utils\ProgressLoader;
use Strapi\Core\Strapi;
use Strapi\DataTransfer\Engine\Engine;
use Strapi\DataTransfer\Strapi\Providers\LocalDestination\LocalDestination;
use Strapi\DataTransfer\Strapi\Providers\LocalSource\LocalSource;
use Strapi\DataTransfer\Strapi\Providers\RemoteDestination\RemoteDestination;
use Strapi\DataTransfer\Strapi\Providers\RemoteSource\RemoteSource;
use Strapi\DataTransfer\Types\Providers\IDestinationProvider;
use Strapi\DataTransfer\Types\Providers\ISourceProvider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/transfer/action.ts: transfers data between the
 * local Strapi and a remote Strapi instance (Node or PHP).
 *
 * @phpstan-type TransferDeps array{createStrapiInstance?: callable(): Strapi, createLocalStrapiSourceProvider?: callable(array<string, mixed>): ISourceProvider, createRemoteStrapiSourceProvider?: callable(array<string, mixed>): ISourceProvider, createLocalStrapiDestinationProvider?: callable(array<string, mixed>): IDestinationProvider, createRemoteStrapiDestinationProvider?: callable(array<string, mixed>): IDestinationProvider, createTransferEngine?: callable(ISourceProvider, IDestinationProvider, array<string, mixed>): Engine, cwd?: string}
 */
final class Action
{
    /** @param TransferDeps $deps */
    public function __construct(private readonly array $deps = [])
    {
    }

    private static function resolveRemotePullAssetIdleTimeoutMs(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $opts `from`, `fromToken`, `to`, `toToken`, `verbose`, `only`,
     *                                   `exclude`, `excludeContentTypes`, `onlyContentTypes`,
     *                                   `filesAutoExcluded`, `throttle`, `force`, `checksums`
     */
    public function __invoke(array $opts, InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->run($opts, $input, $output);
        } catch (ExitError $exit) {
            return Helpers::exitWith($exit->exitCode, $exit->messages, $output);
        }
    }

    /** @param array<string, mixed> $opts */
    private function run(array $opts, InputInterface $input, OutputInterface $output): int
    {
        $from = $opts['from'] ?? null;
        $to = $opts['to'] ?? null;

        if (!($from || $to) || ($from && $to)) {
            throw new ExitError(1, 'Exactly one source (from) or destination (to) option must be provided');
        }

        DataTransfer::normalizeTransferFilterOptions($opts);

        $strapi = ($this->deps['createStrapiInstance'] ?? fn (): Strapi => DataTransfer::createStrapiInstance(['cwd' => $this->deps['cwd'] ?? null]))();
        DataTransfer::validateContentTypeTransferOptionsForStrapi($opts, $strapi->contentTypes());
        $checksumsEnabled = ($opts['checksums'] ?? null) !== false;

        // if no URL provided, use local Strapi
        if (!$from) {
            $source = ($this->deps['createLocalStrapiSourceProvider'] ?? LocalSource::createLocalStrapiSourceProvider(...))([
                'getStrapi' => static fn (): Strapi => $strapi,
            ]);
        }
        // if URL provided, set up a remote source provider
        else {
            if (empty($opts['fromToken'])) {
                throw new ExitError(1, 'Missing token for remote destination');
            }

            $assetIdleTimeoutMs = self::resolveRemotePullAssetIdleTimeoutMs($strapi->config()->get('server.transfer.remote.assetIdleTimeoutMs'));

            $source = ($this->deps['createRemoteStrapiSourceProvider'] ?? RemoteSource::createRemoteStrapiSourceProvider(...))([
                'getStrapi' => static fn (): Strapi => $strapi,
                'url' => (string) $from,
                'auth' => [
                    'type' => 'token',
                    'token' => (string) $opts['fromToken'],
                ],
                ...($assetIdleTimeoutMs !== null ? ['streamTimeout' => $assetIdleTimeoutMs] : []),
                ...($checksumsEnabled ? ['verifyChecksums' => true] : []),
            ]);
        }

        /** Wired after `engine` exists so destination prep can update the CLI spinner. */
        $state = new class () {
            /** @var \Closure(string): void */
            public \Closure $emit;

            /** Shown until destination prep emits a step; then the step is appended after " — ". */
            public ?string $prepStepDetail = null;

            public ?ProgressLoader $startingSpinner = null;

            /** Set when `transfer::start` fires so a final line with the elapsed time can be printed. */
            public ?int $transferPrepStartedAt = null;

            public function __construct()
            {
                $this->emit = static function (string $message): void {
                    // replaced below once `progress` exists
                };
            }
        };
        $onTransferPhase = static function (string $message) use ($state): void {
            ($state->emit)($message);
        };

        // if no URL provided, use local Strapi
        if (!$to) {
            $destination = ($this->deps['createLocalStrapiDestinationProvider'] ?? LocalDestination::createLocalStrapiDestinationProvider(...))([
                'getStrapi' => static fn (): Strapi => $strapi,
                'strategy' => 'restore',
                'restore' => DataTransfer::parseRestoreFromOptions($opts, $strapi->contentTypes()),
                'onTransferPhase' => $onTransferPhase,
            ]);
        }
        // if URL provided, set up a remote destination provider
        else {
            if (empty($opts['toToken'])) {
                throw new ExitError(1, 'Missing token for remote destination');
            }

            $destination = ($this->deps['createRemoteStrapiDestinationProvider'] ?? RemoteDestination::createRemoteStrapiDestinationProvider(...))([
                'url' => (string) $to,
                'auth' => [
                    'type' => 'token',
                    'token' => (string) $opts['toToken'],
                ],
                'strategy' => 'restore',
                'restore' => DataTransfer::parseRestoreFromOptions($opts, $strapi->contentTypes()),
                'onTransferPhase' => $onTransferPhase,
                ...($checksumsEnabled ? ['verifyChecksums' => true] : []),
            ]);
        }

        $engine = ($this->deps['createTransferEngine'] ?? Engine::createTransferEngine(...))($source, $destination, [
            'versionStrategy' => 'exact',
            'schemaStrategy' => 'strict',
            'exclude' => $opts['exclude'] ?? null,
            'only' => $opts['only'] ?? null,
            'throttle' => $opts['throttle'] ?? null,
            'transforms' => DataTransfer::buildTransferTransforms($opts),
        ]);

        $engine->diagnostics->onDiagnostic(DataTransfer::formatDiagnostic('transfer', (bool) ($opts['verbose'] ?? false), $output, $this->deps['cwd'] ?? null));

        $progress = $engine->progress->stream;

        $startingTransferPrefix = 'Starting transfer…';

        $formatPrepSpinnerLine = static function () use ($state, $startingTransferPrefix): string {
            $detail = $state->prepStepDetail;

            return $detail !== null && $detail !== '' ? "{$startingTransferPrefix} — {$detail}" : $startingTransferPrefix;
        };

        $state->emit = static function (string $message) use ($state, $progress, $formatPrepSpinnerLine): void {
            $state->prepStepDetail = $message;
            $progress->emit('transfer::phase', ['message' => $formatPrepSpinnerLine()]);
        };

        $loaders = DataTransfer::loadersFactory($output);

        /*
         * Stops the "starting transfer" spinner and leaves a finished line in the console (like stage
         * `succeed`/`fail`), so the next stage spinner starts on a new line instead of replacing this one.
         */
        $finishStartingSpinner = static function (string $outcome = 'done') use ($state, $formatPrepSpinnerLine): void {
            $spinner = $state->startingSpinner;
            if ($spinner !== null) {
                $startedAt = $state->transferPrepStartedAt;
                $elapsed = $startedAt !== null ? (int) floor(microtime(true) * 1000) - $startedAt : 0;
                $line = $formatPrepSpinnerLine() . Helpers::TRANSFER_PROGRESS_FIELD_SEP . Helpers::formatElapsedAndMaybeRemainingLabel($elapsed, null);
                if ($outcome === 'fail') {
                    $spinner->fail($line);
                } else {
                    $spinner->succeed($line);
                }
                $state->startingSpinner = null;
                $state->transferPrepStartedAt = null;
            }
        };

        $engine->onSchemaDiff(DataTransfer::getDiffHandler($engine, ['force' => $opts['force'] ?? false, 'action' => 'transfer', 'strapi' => $strapi], $input, $output));

        $engine->addErrorHandler(
            'ASSETS_DIRECTORY_ERR',
            DataTransfer::getAssetsBackupHandler($engine, ['force' => $opts['force'] ?? false, 'action' => 'transfer', 'strapi' => $strapi], $input, $output)
        );

        $progress->on('transfer::phase', static function (mixed $payload) use ($state): void {
            $spinner = $state->startingSpinner;
            if ($spinner !== null && is_array($payload)) {
                $spinner->setText((string) ($payload['message'] ?? ''));
            }
        });

        $progress->on('stage::start', static function (mixed $payload) use ($loaders, $finishStartingSpinner): void {
            $finishStartingSpinner('done');
            $loaders->updateLoader((string) $payload['stage'], $payload['data'])->start();
        });

        $progress->on('stage::finish', static function (mixed $payload) use ($loaders): void {
            $loaders->updateLoader((string) $payload['stage'], $payload['data'])->succeed();
        });

        $progress->on('stage::progress', static function (mixed $payload) use ($loaders): void {
            $loaders->updateLoader((string) $payload['stage'], $payload['data']);
        });

        $progress->on('stage::error', static function (mixed $payload) use ($loaders): void {
            $loaders->updateLoader((string) $payload['stage'], $payload['data'])->fail();
        });

        $progress->on('transfer::finish', static function () use ($finishStartingSpinner): void {
            $finishStartingSpinner('done');
        });
        $progress->on('transfer::error', static function () use ($finishStartingSpinner): void {
            $finishStartingSpinner('fail');
        });

        $progress->on('transfer::start', static function () use ($state, $opts, $output, $formatPrepSpinnerLine, $strapi, $engine): void {
            $state->transferPrepStartedAt = (int) floor(microtime(true) * 1000);
            $state->prepStepDetail = null;
            DataTransfer::logTransferFilterSummary([
                'exclude' => $opts['exclude'] ?? null,
                'only' => $opts['only'] ?? null,
                'excludeContentTypes' => $opts['excludeContentTypes'] ?? null,
                'onlyContentTypes' => $opts['onlyContentTypes'] ?? null,
                'filesAutoExcluded' => $opts['filesAutoExcluded'] ?? null,
            ], $output);
            $state->startingSpinner = (new ProgressLoader($output))->start($formatPrepSpinnerLine());

            $strapi->telemetry()->send('didDEITSProcessStart', DataTransfer::getTransferTelemetryPayload($engine));
        });

        try {
            // Abort transfer if user interrupts process
            DataTransfer::setSignalHandler(static fn () => DataTransfer::abortTransfer(['engine' => $engine, 'strapi' => $strapi]));

            $results = $engine->transfer();

            // Note: we need to await telemetry or else the process ends before it is sent
            $strapi->telemetry()->send('didDEITSProcessFinish', DataTransfer::getTransferTelemetryPayload($engine));

            try {
                $table = DataTransfer::buildTransferTable($results['engine']);
                $output->writeln((string) $table, OutputInterface::OUTPUT_RAW);
            } catch (\Throwable) {
                $output->writeln('There was an error displaying the results of the transfer.');
            }

            return Helpers::exitWith(0, DataTransfer::exitMessageText('transfer'), $output);
        } catch (ExitError $exit) {
            throw $exit;
        } catch (\Throwable) {
            $strapi->telemetry()->send('didDEITSProcessFail', DataTransfer::getTransferTelemetryPayload($engine));

            return Helpers::exitWith(1, DataTransfer::exitMessageText('transfer', true), $output);
        }
    }
}
