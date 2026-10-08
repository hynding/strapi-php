<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Import;

use Strapi\Cli\Cli\Utils\DataTransfer;
use Strapi\Cli\Cli\Utils\ExitError;
use Strapi\Cli\Cli\Utils\Helpers;
use Strapi\Core\Strapi;
use Strapi\DataTransfer\Directory\Providers\Source\Source as DirectorySource;
use Strapi\DataTransfer\Engine\Engine;
use Strapi\DataTransfer\File\Providers\Source\Source as FileSource;
use Strapi\DataTransfer\Strapi\Providers\LocalDestination\LocalDestination;
use Strapi\DataTransfer\Types\Providers\IDestinationProvider;
use Strapi\DataTransfer\Types\Providers\ISourceProvider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/import/action.ts: the import command transfers
 * data from a Strapi backup file or unpacked export directory to a local Strapi instance.
 *
 * @phpstan-type ImportDeps array{createStrapiInstance?: callable(): Strapi, createLocalFileSourceProvider?: callable(array<string, mixed>): ISourceProvider, createLocalDirectorySourceProvider?: callable(array<string, mixed>): ISourceProvider, createLocalStrapiDestinationProvider?: callable(array<string, mixed>): IDestinationProvider, createTransferEngine?: callable(ISourceProvider, IDestinationProvider, array<string, mixed>): Engine, cwd?: string}
 */
final class Action
{
    /** @param ImportDeps $deps */
    public function __construct(private readonly array $deps = [])
    {
    }

    /**
     * @param array<string, mixed> $opts `file`, `decompress`, `decrypt`, `verbose`, `key`,
     *                                   `conflictStrategy`, `force`, `only`, `exclude`,
     *                                   `excludeContentTypes`, `onlyContentTypes`, `filesAutoExcluded`, `throttle`
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
        DataTransfer::normalizeTransferFilterOptions($opts);

        $backupPath = (string) ($opts['file'] ?? '');
        if (!file_exists($backupPath)) {
            throw new \RuntimeException("ENOENT: no such file or directory, stat '{$backupPath}'");
        }
        $source = is_dir($backupPath)
            ? ($this->deps['createLocalDirectorySourceProvider'] ?? DirectorySource::createLocalDirectorySourceProvider(...))([
                'directory' => ['path' => $backupPath],
            ])
            : ($this->deps['createLocalFileSourceProvider'] ?? FileSource::createLocalFileSourceProvider(...))(self::getLocalFileSourceOptions($opts));

        /*
         * To local Strapi instance
         */
        $strapiInstance = ($this->deps['createStrapiInstance'] ?? fn (): Strapi => DataTransfer::createStrapiInstance(['cwd' => $this->deps['cwd'] ?? null]))();
        DataTransfer::validateContentTypeTransferOptionsForStrapi($opts, $strapiInstance->contentTypes());

        /*
         * Configure and run the transfer engine
         */
        $engineOptions = [
            'versionStrategy' => Engine::DEFAULT_VERSION_STRATEGY,
            'schemaStrategy' => Engine::DEFAULT_SCHEMA_STRATEGY,
            'exclude' => $opts['exclude'] ?? null,
            'only' => $opts['only'] ?? null,
            'throttle' => $opts['throttle'] ?? null,
            'transforms' => DataTransfer::buildTransferTransforms($opts),
        ];

        $destinationOptions = [
            'getStrapi' => static fn (): Strapi => $strapiInstance,
            'autoDestroy' => false,
            'strategy' => ($opts['conflictStrategy'] ?? null) ?: LocalDestination::DEFAULT_CONFLICT_STRATEGY,
            'restore' => DataTransfer::parseRestoreFromOptions($opts, $strapiInstance->contentTypes()),
        ];

        $destination = ($this->deps['createLocalStrapiDestinationProvider'] ?? LocalDestination::createLocalStrapiDestinationProvider(...))($destinationOptions);
        if (property_exists($destination, 'onWarning')) {
            $destination->onWarning = static function (string $message) use ($output): void {
                $output->writeln("\n" . DataTransfer::chalk('warn', 'yellow') . ": {$message}", OutputInterface::OUTPUT_RAW);
            };
        }

        $engine = ($this->deps['createTransferEngine'] ?? Engine::createTransferEngine(...))($source, $destination, $engineOptions);

        $engine->diagnostics->onDiagnostic(DataTransfer::formatDiagnostic('import', (bool) ($opts['verbose'] ?? false), $output, $this->deps['cwd'] ?? null));

        $progress = $engine->progress->stream;

        $loaders = DataTransfer::loadersFactory($output);

        $engine->onSchemaDiff(DataTransfer::getDiffHandler($engine, ['force' => $opts['force'] ?? false, 'action' => 'import', 'strapi' => $strapiInstance], $input, $output));

        $progress->on('stage::start', static function (mixed $payload) use ($loaders): void {
            $loaders->updateLoader((string) $payload['stage'], $payload['data'])->start();
        });

        $progress->on('stage::finish', static function (mixed $payload) use ($loaders): void {
            $loaders->updateLoader((string) $payload['stage'], $payload['data'])->succeed();
        });

        $progress->on('stage::progress', static function (mixed $payload) use ($loaders): void {
            $loaders->updateLoader((string) $payload['stage'], $payload['data']);
        });

        $progress->on('transfer::start', static function () use ($output, $opts, $strapiInstance, $engine): void {
            $output->writeln('Starting import...');
            DataTransfer::logTransferFilterSummary([
                'exclude' => $opts['exclude'] ?? null,
                'only' => $opts['only'] ?? null,
                'excludeContentTypes' => $opts['excludeContentTypes'] ?? null,
                'onlyContentTypes' => $opts['onlyContentTypes'] ?? null,
                'filesAutoExcluded' => $opts['filesAutoExcluded'] ?? null,
            ], $output);
            $strapiInstance->telemetry()->send('didDEITSProcessStart', DataTransfer::getTransferTelemetryPayload($engine));
        });

        try {
            // Abort transfer if user interrupts process
            DataTransfer::setSignalHandler(static fn () => DataTransfer::abortTransfer(['engine' => $engine, 'strapi' => $strapiInstance]));

            $results = $engine->transfer();

            try {
                $table = DataTransfer::buildTransferTable($results['engine']);
                $output->writeln((string) $table, OutputInterface::OUTPUT_RAW);
            } catch (\Throwable) {
                $output->writeln('There was an error displaying the results of the transfer.');
            }

            // Note: we need to await telemetry or else the process ends before it is sent
            $strapiInstance->telemetry()->send('didDEITSProcessFinish', DataTransfer::getTransferTelemetryPayload($engine));
            $strapiInstance->destroy();

            return Helpers::exitWith(0, DataTransfer::exitMessageText('import'), $output);
        } catch (ExitError $exit) {
            throw $exit;
        } catch (\Throwable) {
            $strapiInstance->telemetry()->send('didDEITSProcessFail', DataTransfer::getTransferTelemetryPayload($engine));

            return Helpers::exitWith(1, DataTransfer::exitMessageText('import', true), $output);
        }
    }

    /**
     * Infer local file source provider options based on a given filename
     *
     * @param array<string, mixed> $opts
     *
     * @return array{file: array{path: string}, compression: array{enabled: bool}, encryption: array{enabled: bool, key: string|null}}
     */
    public static function getLocalFileSourceOptions(array $opts): array
    {
        $key = $opts['key'] ?? null;

        return [
            'file' => ['path' => (string) ($opts['file'] ?? '')],
            'compression' => ['enabled' => !empty($opts['decompress'])],
            'encryption' => ['enabled' => !empty($opts['decrypt']), 'key' => is_string($key) ? $key : null],
        ];
    }
}
