<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Utils;

use Strapi\Cli\Strapi as CliStrapi;
use Strapi\Core\Strapi;
use Strapi\DataTransfer\Engine\Engine;
use Strapi\DataTransfer\Engine\Errors\TransferEngineInitializationError;
use Strapi\DataTransfer\Strapi\TransferPolicy;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/utils/data-transfer.ts: what the `export`, `import` and
 * `transfer` commands share — option values and validation, filters, the restore options, the
 * diagnostics log, the progress lines and the results table.
 *
 * CLI options are plain arrays here (commander's `command.opts()`): `exclude`, `only`,
 * `excludeContentTypes`, `onlyContentTypes`, `filesAutoExcluded`, `throttle`, …
 *
 * @phpstan-type CliOptions array<string, mixed>
 */
final class DataTransfer
{
    /** Media library content types — common target for `--exclude-content-types` (see issue #25008). */
    public const array UPLOAD_CONTENT_TYPE_UIDS = ['plugin::upload.file', 'plugin::upload.folder'];

    public const string MEDIA_LIBRARY_PRESET = 'media-library';

    public const array TRANSFER_FILTER_PRESET_DESCRIPTIONS = [
        'content' => 'entities and links (incl. media library DB records)',
        'files' => 'upload binaries in public/uploads (not media library DB records)',
        'config' => 'core store and webhooks',
        self::MEDIA_LIBRARY_PRESET => 'upload binaries and media library DB records (files + plugin::upload.file, plugin::upload.folder)',
    ];

    private const array TRANSFER_STAGE_PRESETS = ['content', 'files', 'config'];

    /** Stages where throughput is dominated by DB work; items/s is more meaningful than JSON byte rate. */
    private const array STAGES_WITH_ITEM_THROUGHPUT = ['entities', 'links'];

    private const int MAX_ETA_MS = 86_400_000;

    /** `chalk` styles, when colors are on */
    public static function chalk(string $text, string ...$styles): string
    {
        if (!Logger::useColors()) {
            return $text;
        }

        $codes = [
            'bold' => ['1', '22'], 'dim' => ['2', '22'], 'red' => ['31', '39'], 'green' => ['32', '39'],
            'yellow' => ['33', '39'], 'blue' => ['34', '39'], 'cyan' => ['36', '39'], 'grey' => ['90', '39'],
            'yellowBright' => ['93', '39'], 'greenBright' => ['92', '39'],
        ];

        foreach (array_reverse($styles) as $style) {
            if (isset($codes[$style])) {
                $text = "\033[{$codes[$style][0]}m{$text}\033[{$codes[$style][1]}m";
            }
        }

        return $text;
    }

    public static function exitMessageText(string $process, bool $error = false): string
    {
        $processCapitalized = ucfirst($process);

        if (!$error) {
            return self::chalk("{$processCapitalized} process has been completed successfully!", 'bold', 'green');
        }

        return self::chalk("{$processCapitalized} process failed.", 'bold', 'red');
    }

    private static function pad(int $n): string
    {
        return ($n < 10 ? '0' : '') . $n;
    }

    private static function yyyymmddHHMMSS(): string
    {
        $date = new \DateTimeImmutable();

        return $date->format('Y')
            . self::pad((int) $date->format('n'))
            . self::pad((int) $date->format('j'))
            . self::pad((int) $date->format('G'))
            . self::pad((int) $date->format('i'))
            . self::pad((int) $date->format('s'));
    }

    public static function getDefaultExportName(): string
    {
        return 'export_' . self::yyyymmddHHMMSS();
    }

    /**
     * The results table (cli-table3 in upstream).
     *
     * @param array<string, array<string, mixed>>|null $resultData
     */
    public static function buildTransferTable(?array $resultData): ?string
    {
        if ($resultData === null) {
            return null;
        }

        $rows = [];
        $totalBytes = 0;
        $totalItems = 0;
        foreach ($resultData as $stage => $item) {
            if (!is_array($item)) {
                continue;
            }

            $bytes = (int) ($item['bytes'] ?? 0);
            $count = (int) ($item['count'] ?? 0);
            $rows[] = [self::chalk((string) $stage, 'bold'), (string) $count, Helpers::readableBytes($bytes, 1, 11) . ' '];
            $totalBytes += $bytes;
            $totalItems += $count;

            if (is_array($item['aggregates'] ?? null)) {
                $aggregates = $item['aggregates'];
                ksort($aggregates, SORT_STRING);
                foreach ($aggregates as $subkey => $subitem) {
                    $rows[] = [
                        '-- ' . self::chalk((string) $subkey, 'bold', 'grey'),
                        self::chalk((string) ($subitem['count'] ?? 0), 'grey'),
                        self::chalk('(' . Helpers::readableBytes((int) ($subitem['bytes'] ?? 0), 1, 11) . ')', 'grey'),
                    ];
                }
            }
        }
        $rows[] = [
            self::chalk('Total', 'bold', 'green'),
            self::chalk((string) $totalItems, 'bold', 'green'),
            self::chalk(Helpers::readableBytes($totalBytes, 1, 11), 'bold', 'green') . ' ',
        ];

        $head = array_map(static fn (string $text): string => self::chalk($text, 'bold', 'blue'), ['Type', 'Count', 'Size']);

        return self::renderTable($head, $rows);
    }

    private static function visibleLength(string $text): int
    {
        return mb_strlen((string) preg_replace('/\033\[[0-9;]*m/', '', $text));
    }

    /**
     * @param list<string>       $head
     * @param list<list<string>> $rows
     */
    private static function renderTable(array $head, array $rows): string
    {
        $widths = [];
        foreach ([$head, ...$rows] as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, self::visibleLength($cell));
            }
        }

        $line = static fn (string $l, string $m, string $r): string => $l . implode($m, array_map(static fn (int $w): string => str_repeat('─', $w + 2), $widths)) . $r;
        $renderRow = static function (array $row, bool $isHead) use ($widths): string {
            $cells = [];
            foreach ($row as $i => $cell) {
                $padding = $widths[$i] - self::visibleLength($cell);
                // first column left aligned, the others right aligned (header left aligned)
                $cells[] = ' ' . ($i === 0 || $isHead ? $cell . str_repeat(' ', $padding) : str_repeat(' ', $padding) . $cell) . ' ';
            }

            return '│' . implode('│', $cells) . '│';
        };

        $out = [$line('┌', '┬', '┐'), $renderRow($head, true), $line('├', '┼', '┤')];
        foreach ($rows as $i => $row) {
            if ($i > 0) {
                $out[] = $line('├', '┼', '┤');
            }
            $out[] = $renderRow($row, false);
        }
        $out[] = $line('└', '┴', '┘');

        return implode("\n", $out);
    }

    public static function isIgnoredContentType(string $type): bool
    {
        return TransferPolicy::isIgnoredOfficialTransferType($type);
    }

    /**
     * @param array{engine: Engine, strapi: Strapi|null} $params
     */
    public static function abortTransfer(array $params): bool
    {
        try {
            // upstream then destroys the instance, which is never reached: abortTransfer() always throws
            $params['engine']->abortTransfer();
        } catch (\Throwable) {
            // ignore because there's not much else we can do
            return false;
        }
    }

    /**
     * We remove the previous handlers (Strapi's bootstrap adds one that exits) and install ours.
     *
     * @param list<int>|null $signals
     */
    public static function setSignalHandler(callable $handler, ?array $signals = null): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        foreach ($signals ?? [SIGINT, SIGTERM, SIGQUIT] as $signal) {
            pcntl_signal($signal, static function () use ($handler): void {
                $handler();
            });
        }
    }

    /** @param array{logLevel?: string|null, cwd?: string|null} $opts */
    public static function createStrapiInstance(array $opts = []): Strapi
    {
        try {
            $level = $opts['logLevel'] ?? 'error';
            putenv("LOG_LEVEL={$level}");
            $_ENV['LOG_LEVEL'] = $level;

            $appContext = CliStrapi::compileStrapi(['appDir' => $opts['cwd'] ?? (string) getcwd()]);
            $app = CliStrapi::createStrapi([...$appContext]);

            return $app->load();
        } catch (\Throwable $error) {
            if (str_contains($error->getMessage(), 'ECONNREFUSED') || str_contains($error->getMessage(), 'Connection refused')) {
                throw new \RuntimeException('Process failed. Check the database connection with your Strapi project.', 0, $error);
            }

            throw $error;
        }
    }

    /** @return list<string> */
    public static function transferDataTypes(): array
    {
        return array_keys(Engine::TRANSFER_GROUP_PRESETS);
    }

    /** @return list<string> */
    public static function transferExcludePresetChoices(): array
    {
        return [...self::transferDataTypes(), self::MEDIA_LIBRARY_PRESET];
    }

    /** @param list<string> $types */
    private static function formatTransferPresetHelp(array $types): string
    {
        return implode('; ', array_map(static fn (string $type): string => "{$type} (" . self::TRANSFER_FILTER_PRESET_DESCRIPTIONS[$type] . ')', $types));
    }

    public static function excludeOptionDescription(): string
    {
        return 'Exclude data: ' . self::formatTransferPresetHelp(self::transferExcludePresetChoices());
    }

    public static function onlyOptionDescription(): string
    {
        return 'Include only these types (plus schemas): ' . self::formatTransferPresetHelp(self::transferDataTypes());
    }

    public static function excludeContentTypesOptionDescription(): string
    {
        return 'Exclude content types from entities and links (e.g. ' . implode(',', self::UPLOAD_CONTENT_TYPE_UIDS) . ' to omit the media library; or use --exclude media-library to skip binaries and upload records — see issue #25008)';
    }

    public const string ONLY_CONTENT_TYPES_OPTION_DESCRIPTION = 'Transfer only these content types in entities and links (e.g. api::article.article)';

    public const string THROTTLE_OPTION_DESCRIPTION = 'Add a delay in milliseconds between each transferred entity';

    /**
     * Apply the argParsers of the shared filter options (`--exclude`, `--only`,
     * `--exclude-content-types`, `--only-content-types`, `--throttle`) to the raw input.
     *
     * @return CliOptions
     */
    public static function parseFilterOptions(InputInterface $input): array
    {
        $opts = [];

        $exclude = $input->getOption('exclude');
        if (is_string($exclude)) {
            $opts['exclude'] = Commander::getParseListWithChoices(self::transferExcludePresetChoices(), 'Invalid options for "exclude"')($exclude);
        }

        $only = $input->getOption('only');
        if (is_string($only)) {
            $opts['only'] = Commander::getParseListWithChoices(self::transferDataTypes(), 'Invalid options for "only"')($only);
        }

        $excludeContentTypes = $input->getOption('exclude-content-types');
        if (is_string($excludeContentTypes)) {
            $opts['excludeContentTypes'] = Commander::parseList($excludeContentTypes);
        }

        $onlyContentTypes = $input->getOption('only-content-types');
        if (is_string($onlyContentTypes)) {
            $opts['onlyContentTypes'] = Commander::parseList($onlyContentTypes);
        }

        $throttle = $input->getOption('throttle');
        if (is_string($throttle)) {
            $opts['throttle'] = Commander::parseInteger($throttle);
        }

        return $opts;
    }

    /** @param CliOptions $opts */
    public static function validateExcludeOnly(array $opts): void
    {
        $exclude = $opts['exclude'] ?? null;
        $only = $opts['only'] ?? null;
        if (!$only || !$exclude) {
            return;
        }

        $choicesInBoth = array_values(array_filter($only, static fn (string $n): bool => in_array($n, $exclude, true)));
        if ($choicesInBoth !== []) {
            throw new ExitError(1, 'Data types may not be used in both "exclude" and "only" in the same command. Found in both: ' . implode(',', $choicesInBoth));
        }
    }

    /** @param CliOptions $opts */
    public static function validateContentTypeTransferOptions(array $opts): void
    {
        $excludeContentTypes = $opts['excludeContentTypes'] ?? [];
        $onlyContentTypes = $opts['onlyContentTypes'] ?? [];

        if ($excludeContentTypes === [] || $onlyContentTypes === []) {
            return;
        }

        $overlap = array_values(array_filter($excludeContentTypes, static fn (string $uid): bool => in_array($uid, $onlyContentTypes, true)));
        if ($overlap !== []) {
            throw new ExitError(1, 'Content types may not be used in both "--exclude-content-types" and "--only-content-types". Found in both: ' . implode(',', $overlap));
        }
    }

    /**
     * @param list<string>              $uids
     * @param array<string, mixed>      $contentTypes uid-keyed
     */
    private static function assertKnownContentTypes(array $uids, array $contentTypes, string $flag): void
    {
        $unknown = array_values(array_filter($uids, static fn (string $uid): bool => !array_key_exists($uid, $contentTypes)));

        if ($unknown !== []) {
            throw new ExitError(1, "Unknown content type(s) for {$flag}: " . implode(', ', $unknown));
        }
    }

    /**
     * @param CliOptions           $opts
     * @param array<string, mixed> $contentTypes `strapi.contentTypes` (uid-keyed)
     */
    public static function validateContentTypeTransferOptionsForStrapi(array $opts, array $contentTypes): void
    {
        if (!empty($opts['excludeContentTypes'])) {
            self::assertKnownContentTypes($opts['excludeContentTypes'], $contentTypes, '--exclude-content-types');
        }

        if (!empty($opts['onlyContentTypes'])) {
            self::assertKnownContentTypes($opts['onlyContentTypes'], $contentTypes, '--only-content-types');
        }
    }

    /** @param CliOptions $opts */
    public static function shouldIncludeContentTypeInTransfer(string $uid, array $opts): bool
    {
        if (self::isIgnoredContentType($uid)) {
            return false;
        }

        if (in_array($uid, $opts['excludeContentTypes'] ?? [], true)) {
            return false;
        }

        if (!empty($opts['onlyContentTypes'])) {
            return in_array($uid, $opts['onlyContentTypes'], true);
        }

        return true;
    }

    /**
     * @param CliOptions $opts
     *
     * @return \Closure(mixed): bool
     */
    public static function createEntityFilter(array $opts): \Closure
    {
        return static fn (mixed $entity): bool => self::shouldIncludeContentTypeInTransfer(is_array($entity) ? (string) ($entity['type'] ?? '') : '', $opts);
    }

    /**
     * @param CliOptions $opts
     *
     * @return \Closure(mixed): bool
     */
    public static function createLinkFilter(array $opts): \Closure
    {
        return static fn (mixed $link): bool => is_array($link)
            && self::shouldIncludeContentTypeInTransfer((string) ($link['left']['type'] ?? ''), $opts)
            && self::shouldIncludeContentTypeInTransfer((string) ($link['right']['type'] ?? ''), $opts);
    }

    /**
     * @param CliOptions $opts
     *
     * @return array{links: list<array{filter: \Closure(mixed): bool}>, entities: list<array{filter: \Closure(mixed): bool}>}
     */
    public static function buildTransferTransforms(array $opts): array
    {
        return [
            'links' => [['filter' => self::createLinkFilter($opts)]],
            'entities' => [['filter' => self::createEntityFilter($opts)]],
        ];
    }

    /**
     * The diagnostics listener: every diagnostic goes to `<operation>_<timestamp>.log` (info and
     * above), warnings and errors also to the console (infos too with `--verbose`).
     *
     * @return \Closure(array<string, mixed>): void
     */
    public static function formatDiagnostic(string $operation, bool $verbose = false, ?OutputInterface $output = null, ?string $cwd = null): \Closure
    {
        $logFile = null;
        $handle = null;

        $getLogger = static function () use (&$logFile, &$handle, $operation, $cwd, $verbose, $output): \Closure {
            if ($handle === null) {
                $logFileBasename = $operation . '_' . (int) floor(microtime(true) * 1000) . '.log';
                $logFile = rtrim($cwd ?? (string) getcwd(), '/') . '/' . $logFileBasename;
                $handle = @fopen($logFile, 'ab') ?: null;

                $write = self::makeLogWriter($handle, $verbose, $output);
                $write('info', "[{$operation}] Diagnostic log file: {$logFile} (info-level messages are written here even without --verbose)");
            }

            return self::makeLogWriter($handle, $verbose, $output);
        };

        $errorColors = ['fatal' => 'red', 'error' => 'red', 'silly' => 'yellow'];

        return static function (array $diagnostic) use ($getLogger, $errorColors): void {
            $kind = $diagnostic['kind'] ?? null;
            $details = is_array($diagnostic['details'] ?? null) ? $diagnostic['details'] : [];

            try {
                if ($kind === 'error') {
                    $message = (string) ($details['message'] ?? '');
                    $severity = (string) ($details['severity'] ?? 'fatal');

                    $errorMessage = self::chalk('[' . strtoupper($severity) . "] {$message}", $errorColors[$severity] ?? 'red');

                    $getLogger()('error', $errorMessage);
                }
                if ($kind === 'info') {
                    $message = (string) ($details['message'] ?? '');
                    $params = $details['params'] ?? null;
                    $origin = $details['origin'] ?? 'transfer';

                    $msg = "[{$origin}] {$message}\n" . ($params !== null ? (string) json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '');

                    $getLogger()('info', $msg);
                }
                if ($kind === 'warning') {
                    $origin = $details['origin'] ?? 'transfer';
                    $message = (string) ($details['message'] ?? '');

                    $getLogger()('warn', "({$origin}) {$message}");
                }
            } catch (\Throwable $err) {
                $getLogger()('error', $err->getMessage());
            }
        };
    }

    /**
     * @param resource|null $handle
     *
     * @return \Closure(string, string): void
     */
    private static function makeLogWriter(mixed $handle, bool $verbose, ?OutputInterface $output): \Closure
    {
        return static function (string $level, string $message) use ($handle, $verbose, $output): void {
            $plain = (string) preg_replace('/\033\[[0-9;]*m/', '', $message);
            $timestamp = gmdate('Y-m-d\TH:i:s\Z');
            if (is_resource($handle)) {
                fwrite($handle, "[{$timestamp}] {$level}: {$plain}\n");
            }

            $consoleLevels = $verbose ? ['error', 'warn', 'info'] : ['error', 'warn'];
            if (in_array($level, $consoleLevels, true)) {
                $line = "[{$timestamp}] {$level}: {$message}";
                if ($output !== null) {
                    $output->writeln($line, OutputInterface::OUTPUT_RAW);
                } else {
                    fwrite(STDERR, $line . "\n");
                }
            }
        };
    }

    /**
     * Linear ETA from completed amount vs total, using average rate so far (done / elapsedMs).
     * Returns null when progress or totals are not usable yet.
     */
    private static function estimateEtaMs(int|float $elapsedMs, int|float $done, int|float $total): ?float
    {
        if ($elapsedMs < 500 || $done <= 0 || $total <= 0 || $done >= $total) {
            return null;
        }
        $ratePerMs = $done / $elapsedMs;
        $remaining = $total - $done;
        $etaMs = $remaining / $ratePerMs;
        if (!is_finite($etaMs) || $etaMs <= 0 || $etaMs >= self::MAX_ETA_MS) {
            return null;
        }

        return $etaMs;
    }

    /**
     * The per-stage progress lines (ora spinners upstream): `updateLoader(stage, data)` sets the
     * line's text and returns the stage's loader (`start()`, `succeed()`, `fail()`).
     */
    public static function loadersFactory(?OutputInterface $output = null): ProgressLoaders
    {
        return new ProgressLoaders($output);
    }

    /**
     * The text of a stage's progress line.
     *
     * @param array<string, array<string, mixed>> $data
     */
    public static function progressText(string $stage, array $data, ?int $now = null): string
    {
        $now ??= (int) floor(microtime(true) * 1000);
        $stageData = $data[$stage] ?? null;
        $startTime = is_array($stageData) ? ($stageData['startTime'] ?? null) : null;
        $endTime = is_array($stageData) ? ($stageData['endTime'] ?? null) : null;
        $elapsedTime = $startTime ? (($endTime ?: $now) - $startTime) : 0;
        $bytes = (int) ($stageData['bytes'] ?? 0);
        $count = (int) ($stageData['count'] ?? 0);
        $totalBytes = $stageData['totalBytes'] ?? null;
        $totalCount = $stageData['totalCount'] ?? null;

        $countLabel = $totalCount !== null && $totalCount > 0 ? "{$count} / {$totalCount}" : (string) $count;
        $sizeCompact = $totalBytes !== null && $totalBytes > 0
            ? Helpers::readableBytes($bytes) . ' / ' . Helpers::readableBytes((int) $totalBytes)
            : Helpers::readableBytes($bytes);

        $parts = ["{$stage}: {$countLabel} transferred", $sizeCompact];

        $itemThroughput = in_array($stage, self::STAGES_WITH_ITEM_THROUGHPUT, true);

        if ($elapsedTime > 0 && !$endTime) {
            if ($itemThroughput) {
                $itemsPerSec = ($count * 1000) / $elapsedTime;
                $parts[] = number_format($itemsPerSec, 1, '.', '') . ' items/s';
            } else {
                $parts[] = Helpers::readableBytes(($bytes * 1000) / $elapsedTime) . '/s';
            }
        }

        $etaMs = null;
        if (!$endTime) {
            if ($itemThroughput && $totalCount !== null) {
                $etaMs = self::estimateEtaMs($elapsedTime, $count, (int) $totalCount);
            } elseif ($totalBytes !== null) {
                $etaMs = self::estimateEtaMs($elapsedTime, $bytes, (int) $totalBytes);
            }
        }
        $parts[] = Helpers::formatElapsedAndMaybeRemainingLabel($elapsedTime, $etaMs);

        return implode(Helpers::TRANSFER_PROGRESS_FIELD_SEP, $parts);
    }

    /**
     * Get the telemetry data to be sent for a didDEITSProcess* event from an initialized transfer engine object
     *
     * @return array{eventProperties: array{source: string|null, destination: string|null}}
     */
    public static function getTransferTelemetryPayload(?Engine $engine): array
    {
        return [
            'eventProperties' => [
                'source' => $engine !== null ? Engine::providerName($engine->sourceProvider) : null,
                'destination' => $engine !== null ? Engine::providerName($engine->destinationProvider) : null,
            ],
        ];
    }

    /**
     * Get a transfer engine schema diff handler that confirms with the user before bypassing a schema check
     *
     * @param array{force?: bool|null, action: string, strapi?: Strapi|null} $options
     *
     * @return \Closure(\ArrayObject<string, mixed>, callable(\ArrayObject<string, mixed>): mixed): mixed
     */
    public static function getDiffHandler(Engine $engine, array $options, InputInterface $input, OutputInterface $output): \Closure
    {
        $force = (bool) ($options['force'] ?? false);
        $action = $options['action'];
        $strapi = $options['strapi'] ?? null;

        return static function (\ArrayObject $context, callable $next) use ($engine, $force, $action, $strapi, $input, $output): mixed {
            // if we abort here, we need to actually exit the process because of conflict with the prompt
            self::setSignalHandler(static function () use ($engine, $strapi, $action): void {
                self::abortTransfer(['engine' => $engine, 'strapi' => $strapi]);
                throw new ExitError(1, self::exitMessageText($action, true));
            });

            $workflowsStatus = null;
            $source = 'Schema Integrity';

            foreach ($context['diffs'] as $uid => $diffs) {
                foreach ($diffs as $diff) {
                    $path = implode('.', [(string) $uid, ...$diff['path']]);
                    $endPath = $diff['path'] === [] ? null : $diff['path'][array_key_last($diff['path'])];

                    // Catch known features
                    if (
                        $uid === 'plugin::review-workflows.workflow'
                        || $uid === 'plugin::review-workflows.workflow-stage'
                        || (is_string($endPath) && str_starts_with($endPath, 'strapi_stage'))
                        || (is_string($endPath) && str_starts_with($endPath, 'strapi_assignee'))
                    ) {
                        $workflowsStatus = $diff['kind'];
                    }
                    // handle generic cases
                    elseif ($diff['kind'] === 'added') {
                        $engine->reportWarning(self::chalk(self::chalk($path, 'bold') . ' does not exist on source', 'red'), $source);
                    } elseif ($diff['kind'] === 'deleted') {
                        $engine->reportWarning(self::chalk(self::chalk($path, 'bold') . ' does not exist on destination', 'red'), $source);
                    } elseif ($diff['kind'] === 'modified') {
                        $engine->reportWarning(self::chalk(self::chalk($path, 'bold') . ' has a different data type', 'red'), $source);
                    }
                }
            }

            // output the known feature warnings
            if ($workflowsStatus === 'added') {
                $engine->reportWarning(self::chalk('Review workflows feature does not exist on source', 'red'), $source);
            } elseif ($workflowsStatus === 'deleted') {
                $engine->reportWarning(self::chalk('Review workflows feature does not exist on destination', 'red'), $source);
            } elseif ($workflowsStatus === 'modified') {
                $engine->panic(new TransferEngineInitializationError('Unresolved differences in schema [review workflows]'));
            }

            $confirmed = Commander::confirmMessage(
                'There are differences in schema between the source and destination, and the data listed above will be lost. Are you sure you want to continue?',
                $force,
                $input,
                $output
            );

            // reset handler back to normal
            self::setSignalHandler(static fn () => self::abortTransfer(['engine' => $engine, 'strapi' => $strapi]));

            if ($confirmed) {
                // lodash merge(context.diffs, context.ignoredDiffs)
                $context['ignoredDiffs'] = array_replace_recursive($context['diffs'], $context['ignoredDiffs']);
            }

            return $next($context);
        };
    }

    /**
     * @param array{force?: bool|null, action: string, strapi?: Strapi|null} $options
     *
     * @return \Closure(\ArrayObject<string, mixed>, callable(\ArrayObject<string, mixed>): mixed): mixed
     */
    public static function getAssetsBackupHandler(Engine $engine, array $options, InputInterface $input, OutputInterface $output): \Closure
    {
        $force = (bool) ($options['force'] ?? false);
        $action = $options['action'];
        $strapi = $options['strapi'] ?? null;

        return static function (\ArrayObject $context, callable $next) use ($engine, $force, $action, $strapi, $input, $output): mixed {
            // if we abort here, we need to actually exit the process because of conflict with the prompt
            self::setSignalHandler(static function () use ($engine, $strapi, $action): void {
                self::abortTransfer(['engine' => $engine, 'strapi' => $strapi]);
                throw new ExitError(1, self::exitMessageText($action, true));
            });

            $output->writeln('The backup for the assets could not be created inside the public directory. Ensure Strapi has write permissions on the public directory.');
            $confirmed = Commander::confirmMessage('Do you want to continue without backing up your public/uploads files?', $force, $input, $output);

            if ($confirmed) {
                $context['ignore'] = true;
            }

            // reset handler back to normal
            self::setSignalHandler(static fn () => self::abortTransfer(['engine' => $engine, 'strapi' => $strapi]));

            return $next($context);
        };
    }

    /** @return list<string> the strings of an option value (a list) */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @param CliOptions $opts */
    public static function shouldSkipStage(array $opts, string $dataKind): bool
    {
        if (in_array($dataKind, $opts['exclude'] ?? [], true)) {
            return true;
        }
        if (isset($opts['only'])) {
            return !in_array($dataKind, $opts['only'], true);
        }

        return false;
    }

    /** @param CliOptions $opts */
    public static function areUploadContentTypesInTransferScope(array $opts): bool
    {
        foreach (self::UPLOAD_CONTENT_TYPE_UIDS as $uid) {
            if (!self::shouldIncludeContentTypeInTransfer($uid, $opts)) {
                return false;
            }
        }

        return true;
    }

    /** @param CliOptions $opts */
    public static function areAllUploadContentTypesOutOfTransferScope(array $opts): bool
    {
        foreach (self::UPLOAD_CONTENT_TYPE_UIDS as $uid) {
            if (self::shouldIncludeContentTypeInTransfer($uid, $opts)) {
                return false;
            }
        }

        return true;
    }

    /** @param CliOptions $opts */
    private static function isContentStageActive(array $opts): bool
    {
        return !self::shouldSkipStage($opts, 'content');
    }

    /** @param CliOptions $opts */
    private static function expandMediaLibraryPreset(array &$opts): void
    {
        if (!in_array(self::MEDIA_LIBRARY_PRESET, $opts['exclude'] ?? [], true)) {
            return;
        }

        $opts['exclude'] = array_values(array_filter($opts['exclude'], static fn (string $item): bool => $item !== self::MEDIA_LIBRARY_PRESET));

        if (!in_array('files', $opts['exclude'], true)) {
            $opts['exclude'][] = 'files';
        }

        $excludeContentTypes = $opts['excludeContentTypes'] ?? [];
        foreach (self::UPLOAD_CONTENT_TYPE_UIDS as $uid) {
            if (!in_array($uid, $excludeContentTypes, true)) {
                $excludeContentTypes[] = $uid;
            }
        }
        $opts['excludeContentTypes'] = $excludeContentTypes;
    }

    /** @param CliOptions $opts */
    private static function autoExcludeFilesWhenUploadTypesOutOfScope(array &$opts): void
    {
        if (
            !empty($opts['filesAutoExcluded'])
            || !self::isContentStageActive($opts)
            || in_array('files', $opts['only'] ?? [], true)
            || self::shouldSkipStage($opts, 'files')
            || !self::areAllUploadContentTypesOutOfTransferScope($opts)
        ) {
            return;
        }

        $opts['exclude'] = [...($opts['exclude'] ?? []), 'files'];
        $opts['filesAutoExcluded'] = true;
    }

    /**
     * @param CliOptions $opts
     *
     * @return CliOptions
     */
    public static function normalizeTransferFilterOptions(array &$opts): array
    {
        self::expandMediaLibraryPreset($opts);
        self::autoExcludeFilesWhenUploadTypesOutOfScope($opts);

        return $opts;
    }

    /**
     * @param CliOptions $opts
     *
     * @return list<string> the lines printed
     */
    public static function logTransferFilterSummary(array $opts, ?OutputInterface $output = null): array
    {
        $exclude = $opts['exclude'] ?? [];
        $only = $opts['only'] ?? [];
        $excludeContentTypes = $opts['excludeContentTypes'] ?? [];
        $onlyContentTypes = $opts['onlyContentTypes'] ?? [];
        $lines = [];

        if ($exclude === [] && $only === [] && $excludeContentTypes === [] && $onlyContentTypes === []) {
            return $lines;
        }

        $parts = [];
        if ($exclude !== []) {
            $parts[] = 'excluding ' . implode(', ', $exclude);
        }
        if ($only !== []) {
            $parts[] = 'only ' . implode(', ', $only);
        }

        if ($parts !== []) {
            $lines[] = self::chalk('Transfer filters: ' . implode('; ', $parts) . '.', 'dim');
        }

        // When `--only` omits stages, say so — destination data for those stages is preserved.
        if ($only !== []) {
            $omittedStages = array_values(array_filter(self::TRANSFER_STAGE_PRESETS, static fn (string $stage): bool => !in_array($stage, $only, true)));
            if ($omittedStages !== []) {
                $lines[] = self::chalk('Stages not transferred (destination data preserved): ' . implode(', ', $omittedStages) . '.', 'dim');
            }
        }

        $contentTypeParts = [];
        if ($excludeContentTypes !== []) {
            $contentTypeParts[] = 'excluding ' . implode(', ', $excludeContentTypes);
        }
        if ($onlyContentTypes !== []) {
            $contentTypeParts[] = 'only ' . implode(', ', $onlyContentTypes);
        }

        if ($contentTypeParts !== []) {
            $lines[] = self::chalk('Content type filters: ' . implode('; ', $contentTypeParts) . '.', 'dim');
        }

        if (!empty($opts['filesAutoExcluded'])) {
            $lines[] = self::chalk('Skipping files stage: upload content types are not in transfer scope (plugin::upload.file, plugin::upload.folder).', 'dim');
        }

        if (
            self::shouldSkipStage($opts, 'files')
            && !self::shouldSkipStage($opts, 'content')
            && self::areUploadContentTypesInTransferScope($opts)
            && empty($opts['filesAutoExcluded'])
        ) {
            $lines[] = self::chalk('Note: Media library records (plugin::upload.file, plugin::upload.folder) are still transferred with the rest of your content (the entities stage). Sync upload binaries separately (e.g. rsync public/uploads).', 'dim');
        }

        foreach ($lines as $line) {
            $output?->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $lines;
    }

    /**
     * Based on exclude/only from options, create the restore object to match
     *
     * @param CliOptions           $opts
     * @param array<string, mixed> $contentTypes `strapi.contentTypes` (uid-keyed)
     *
     * @return array{entities: array{exclude: list<string>, include?: list<string>}, assets: bool, configuration: array{webhook: bool, coreStore: bool}}
     */
    public static function parseRestoreFromOptions(array $opts, array $contentTypes): array
    {
        $uids = array_map('strval', array_keys($contentTypes));
        $excludeContentTypes = self::stringList($opts['excludeContentTypes'] ?? []);
        $onlyContentTypes = self::stringList($opts['onlyContentTypes'] ?? []);

        $entitiesOptions = [
            'exclude' => [
                ...array_values(array_filter($uids, self::isIgnoredContentType(...))),
                ...TransferPolicy::getIgnoredOfficialTransferTypes(),
                ...$excludeContentTypes,
            ],
        ];

        $contentInScope = !((isset($opts['only']) && !in_array('content', $opts['only'], true)) || in_array('content', $opts['exclude'] ?? [], true));

        if (!$contentInScope) {
            // Nothing from the entities stage is transferred; do not delete any records beforehand.
            $entitiesOptions['include'] = [];
        } elseif ($onlyContentTypes !== []) {
            // Only wipe content types that are being replaced by this transfer.
            $entitiesOptions['include'] = $onlyContentTypes;
        } elseif (self::shouldSkipStage($opts, 'config')) {
            // When config is excluded, scope pre-transfer deletion to user content types only.
            // Internal models (e.g. strapi::core-store) must not be wiped via the entities path.
            $entitiesOptions['include'] = array_values(array_filter(
                $uids,
                static fn (string $uid): bool => !self::isIgnoredContentType($uid) && !in_array($uid, $excludeContentTypes, true)
            ));
        }

        return [
            'entities' => $entitiesOptions,
            'assets' => !self::shouldSkipStage($opts, 'files'),
            'configuration' => [
                'webhook' => !self::shouldSkipStage($opts, 'config'),
                'coreStore' => !self::shouldSkipStage($opts, 'config'),
            ],
        ];
    }
}
