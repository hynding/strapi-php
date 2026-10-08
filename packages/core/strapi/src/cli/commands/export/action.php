<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Export;

use Strapi\Cli\Cli\Utils\DataTransfer;
use Strapi\Cli\Cli\Utils\ExitError;
use Strapi\Cli\Cli\Utils\Helpers;
use Strapi\Core\Strapi;
use Strapi\DataTransfer\Directory\Providers\Destination\Destination as DirectoryDestination;
use Strapi\DataTransfer\Engine\Engine;
use Strapi\DataTransfer\Engine\Errors\TransferEngineTransferError;
use Strapi\DataTransfer\File\Providers\Destination\Destination as FileDestination;
use Strapi\DataTransfer\Strapi\Providers\LocalSource\LocalSource;
use Strapi\DataTransfer\Types\Providers\IDestinationProvider;
use Strapi\DataTransfer\Types\Providers\ISourceProvider;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/export/action.ts: the export command transfers
 * data from a local Strapi instance to a file (or an unpacked directory).
 *
 * The factories (`$deps`) default to the real ones; tests replace them, as upstream's tests mock
 * the modules.
 *
 * @phpstan-type ExportDeps array{createStrapiInstance?: callable(): Strapi, createLocalStrapiSourceProvider?: callable(array<string, mixed>): ISourceProvider, createLocalFileDestinationProvider?: callable(array<string, mixed>): IDestinationProvider, createLocalDirectoryDestinationProvider?: callable(array<string, mixed>): IDestinationProvider, createTransferEngine?: callable(ISourceProvider, IDestinationProvider, array<string, mixed>): Engine, getDefaultExportName?: callable(): string, pathExists?: callable(string): bool, cwd?: string}
 */
final class Action
{
    private const int BYTES_IN_MB = 1024 * 1024;

    /** @param ExportDeps $deps */
    public function __construct(private readonly array $deps = [])
    {
    }

    /**
     * @param array<string, mixed> $opts `file`, `format`, `encrypt`, `verbose`, `key`, `compress`,
     *                                   `only`, `exclude`, `excludeContentTypes`, `onlyContentTypes`,
     *                                   `filesAutoExcluded`, `throttle`, `maxSizeJsonl`
     */
    public function __invoke(array $opts, OutputInterface $output): int
    {
        try {
            return $this->run($opts, $output);
        } catch (ExitError $exit) {
            return Helpers::exitWith($exit->exitCode, $exit->messages, $output);
        }
    }

    /** @param array<string, mixed> $opts */
    private function run(array $opts, OutputInterface $output): int
    {
        ValidateDirFormat::normalizeExportDirFormatOpts($opts);
        DataTransfer::normalizeTransferFilterOptions($opts);

        $strapi = ($this->deps['createStrapiInstance'] ?? fn (): Strapi => DataTransfer::createStrapiInstance(['cwd' => $this->deps['cwd'] ?? null]))();
        DataTransfer::validateContentTypeTransferOptionsForStrapi($opts, $strapi->contentTypes());

        $source = $this->createSourceProvider($strapi);
        $destination = $this->createDestinationProvider($opts);

        $engineOptions = [
            'versionStrategy' => 'ignore', // for an export to file, versionStrategy will always be skipped
            'schemaStrategy' => 'ignore', // for an export to file, schemaStrategy will always be skipped
            'exclude' => $opts['exclude'] ?? null,
            'only' => $opts['only'] ?? null,
            'throttle' => $opts['throttle'] ?? null,
            'transforms' => DataTransfer::buildTransferTransforms($opts),
        ];
        $engine = ($this->deps['createTransferEngine'] ?? Engine::createTransferEngine(...))($source, $destination, $engineOptions);

        $engine->diagnostics->onDiagnostic(DataTransfer::formatDiagnostic('export', (bool) ($opts['verbose'] ?? false), $output, $this->deps['cwd'] ?? null));

        $progress = $engine->progress->stream;

        $loaders = DataTransfer::loadersFactory($output);

        $progress->on('stage::start', static function (mixed $payload) use ($loaders): void {
            $loaders->updateLoader((string) $payload['stage'], $payload['data'])->start();
        });

        $progress->on('stage::finish', static function (mixed $payload) use ($loaders): void {
            $loaders->updateLoader((string) $payload['stage'], $payload['data'])->succeed();
        });

        $progress->on('stage::progress', static function (mixed $payload) use ($loaders): void {
            $loaders->updateLoader((string) $payload['stage'], $payload['data']);
        });

        $progress->on('transfer::start', static function () use ($output, $opts, $strapi, $engine): void {
            $output->writeln('Starting export...');
            DataTransfer::logTransferFilterSummary([
                'exclude' => $opts['exclude'] ?? null,
                'only' => $opts['only'] ?? null,
                'excludeContentTypes' => $opts['excludeContentTypes'] ?? null,
                'onlyContentTypes' => $opts['onlyContentTypes'] ?? null,
                'filesAutoExcluded' => $opts['filesAutoExcluded'] ?? null,
            ], $output);

            $strapi->telemetry()->send('didDEITSProcessStart', DataTransfer::getTransferTelemetryPayload($engine));
        });

        try {
            // Abort transfer if user interrupts process
            DataTransfer::setSignalHandler(static fn () => DataTransfer::abortTransfer(['engine' => $engine, 'strapi' => $strapi]));

            $results = $engine->transfer();
            $destinationResults = $results['destination'] ?? null;
            $outFile = (string) (is_array($destinationResults) ? ($destinationResults['file']['path'] ?? '') : '');
            $pathExists = $this->deps['pathExists'] ?? static fn (string $path): bool => file_exists($path);
            if (($opts['format'] ?? 'tar') === 'dir') {
                $metadataPath = rtrim($outFile, '/') . '/metadata.json';
                if (!$pathExists($metadataPath)) {
                    throw new TransferEngineTransferError("Export directory was not created correctly \"{$outFile}\"");
                }
            } elseif (!$pathExists($outFile)) {
                throw new TransferEngineTransferError("Export file not created \"{$outFile}\"");
            }

            // Note: we need to await telemetry or else the process ends before it is sent
            $strapi->telemetry()->send('didDEITSProcessFinish', DataTransfer::getTransferTelemetryPayload($engine));

            try {
                $table = DataTransfer::buildTransferTable($results['engine']);
                $output->writeln((string) $table, OutputInterface::OUTPUT_RAW);
            } catch (\Throwable) {
                $output->writeln('There was an error displaying the results of the transfer.');
            }

            $output->writeln('Export archive is in ' . DataTransfer::chalk($outFile, 'green'), OutputInterface::OUTPUT_RAW);

            return Helpers::exitWith(0, DataTransfer::exitMessageText('export'), $output);
        } catch (ExitError $exit) {
            throw $exit;
        } catch (\Throwable) {
            $strapi->telemetry()->send('didDEITSProcessFail', DataTransfer::getTransferTelemetryPayload($engine));

            return Helpers::exitWith(1, DataTransfer::exitMessageText('export', true), $output);
        }
    }

    /**
     * It creates a local strapi source provider
     */
    private function createSourceProvider(Strapi $strapi): ISourceProvider
    {
        $options = ['getStrapi' => static fn (): Strapi => $strapi];

        return ($this->deps['createLocalStrapiSourceProvider'] ?? LocalSource::createLocalStrapiSourceProvider(...))($options);
    }

    /**
     * It creates a local file or directory destination provider based on the given options
     *
     * @param array<string, mixed> $opts
     */
    private function createDestinationProvider(array $opts): IDestinationProvider
    {
        $file = $opts['file'] ?? null;
        $compress = $opts['compress'] ?? null;
        $encrypt = $opts['encrypt'] ?? null;
        $key = $opts['key'] ?? null;
        $maxSizeJsonl = $opts['maxSizeJsonl'] ?? null;
        $format = $opts['format'] ?? 'tar';

        $filepath = is_string($file) && $file !== '' ? $file : ($this->deps['getDefaultExportName'] ?? DataTransfer::getDefaultExportName(...))();

        $maxSizeJsonlInMb = is_numeric($maxSizeJsonl) ? (float) $maxSizeJsonl * self::BYTES_IN_MB : null;

        if ($format === 'dir') {
            $cwd = $this->deps['cwd'] ?? (string) getcwd();
            $dirPath = str_starts_with($filepath, '/') ? $filepath : DirectoryDestination::resolve(rtrim($cwd, '/') . '/' . $filepath);

            return ($this->deps['createLocalDirectoryDestinationProvider'] ?? DirectoryDestination::createLocalDirectoryDestinationProvider(...))([
                'directory' => ['path' => $dirPath],
                'file' => [
                    'maxSizeJsonl' => $maxSizeJsonlInMb,
                ],
            ]);
        }

        return ($this->deps['createLocalFileDestinationProvider'] ?? FileDestination::createLocalFileDestinationProvider(...))([
            'file' => [
                'path' => $filepath,
                'maxSizeJsonl' => $maxSizeJsonlInMb,
            ],
            'encryption' => [
                'enabled' => (bool) ($encrypt ?? false),
                'key' => $encrypt ? $key : null,
            ],
            'compression' => [
                'enabled' => (bool) ($compress ?? false),
            ],
        ]);
    }
}
