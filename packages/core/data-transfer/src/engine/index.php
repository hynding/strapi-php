<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Engine;

use Strapi\DataTransfer\Engine\Errors\TransferEngineValidationError;
use Strapi\DataTransfer\Engine\Validation\Provider as ProviderValidation;
use Strapi\DataTransfer\Engine\Validation\Schemas\Schemas;
use Strapi\DataTransfer\Errors\DataTransferError;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Types\Providers\IDestinationProvider;
use Strapi\DataTransfer\Types\Providers\IProvider;
use Strapi\DataTransfer\Types\Providers\ISourceProvider;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Json;
use Strapi\DataTransfer\Utils\Middleware;
use Strapi\DataTransfer\Utils\Stream as StreamUtils;
use Strapi\DataTransfer\Utils\Stream\EventEmitter;
use Strapi\DataTransfer\Utils\Stream\PassThrough;
use Strapi\DataTransfer\Utils\Stream\Writable;

/**
 * Port of src/engine/index.ts: the transfer engine (`TransferEngine`, `createTransferEngine()`),
 * the stage list and the filter presets.
 *
 * Streams are synchronous here: a source stage stream is an `iterable`, a destination stage
 * stream a {@see Writable}; `transferStage` pipes one into the other through the stage transforms
 * and the progress tracker. Asset streams (`$asset['stream']`) are iterables of byte strings.
 *
 * Options (upstream `ITransferEngineOptions`): `versionStrategy` (`exact`|`ignore`|`major`|`minor`|`patch`),
 * `schemaStrategy` (`exact`|`strict`|`ignore`), `transforms` (`[stage|'global' => list<['filter' => callable]|['map' => callable]>]`),
 * `exclude` / `only` (lists of `content`|`files`|`config`), `throttle` (ms after each record).
 *
 * Handler contexts are `\ArrayObject`s so middlewares can change them as upstream's do:
 * schema diff `{ ignoredDiffs, diffs, source, destination }`, error `{ ignore }`.
 *
 * @phpstan-type EngineOptions array{versionStrategy?: string, schemaStrategy?: string, transforms?: array<string, list<array<string, callable>>>, exclude?: list<string>|null, only?: list<string>|null, throttle?: int|float|null}
 * @phpstan-import-type StageProgress from Progress
 */
final class Engine
{
    public const array TRANSFER_STAGES = ['entities', 'links', 'assets', 'schemas', 'configuration'];

    /** Preset filters for only/exclude options */
    public const array TRANSFER_GROUP_PRESETS = [
        'content' => [
            'links' => true, // Example: content includes the entire links stage
            'entities' => true,
        ],
        'files' => [
            'assets' => true,
        ],
        'config' => [
            'configuration' => true,
        ],
    ];

    public const string DEFAULT_VERSION_STRATEGY = 'ignore';

    public const string DEFAULT_SCHEMA_STRATEGY = 'strict';

    public ISourceProvider $sourceProvider;

    public IDestinationProvider $destinationProvider;

    /** @var EngineOptions */
    public array $options;

    /**
     * Progress of the current stage: `data` (metrics such as size and record count, per stage) and
     * `stream` (emits `transfer::*` and `stage::*` events).
     */
    public Progress $progress;

    public Diagnostic $diagnostics;

    /** @var array{source?: array<string, mixed>, destination?: array<string, mixed>} */
    private array $metadata = [];

    /** @var array{source?: array<string, mixed>|null, destination?: array<string, mixed>|null} */
    private array $schema = [];

    /** @var array{schemaDiff: list<callable>, errors: array<string, list<callable>>} */
    private array $handlers = ['schemaDiff' => [], 'errors' => []];

    private bool $aborted = false;

    private bool $closed = false;

    /** @param EngineOptions $options */
    public function __construct(ISourceProvider $sourceProvider, IDestinationProvider $destinationProvider, array $options)
    {
        $this->diagnostics = Diagnostic::createDiagnosticReporter();

        ProviderValidation::validateProvider('source', $sourceProvider);
        ProviderValidation::validateProvider('destination', $destinationProvider);

        $this->sourceProvider = $sourceProvider;
        $this->destinationProvider = $destinationProvider;
        $this->options = $options;

        $this->progress = new Progress(new EventEmitter());
    }

    /** @param EngineOptions $options */
    public static function createTransferEngine(ISourceProvider $sourceProvider, IDestinationProvider $destinationProvider, array $options): self
    {
        return new self($sourceProvider, $destinationProvider, $options);
    }

    /** @param callable(\ArrayObject<string, mixed>, callable(\ArrayObject<string, mixed>): void): mixed $handler */
    public function onSchemaDiff(callable $handler): void
    {
        $this->handlers['schemaDiff'][] = $handler;
    }

    /** @param callable(\ArrayObject<string, mixed>, callable(\ArrayObject<string, mixed>): void): mixed $handler */
    public function addErrorHandler(string $handlerName, callable $handler): void
    {
        $this->handlers['errors'][$handlerName] ??= [];
        $this->handlers['errors'][$handlerName][] = $handler;
    }

    public function attemptResolveError(\Throwable $error): bool
    {
        /** @var \ArrayObject<string, mixed> $context */
        $context = new \ArrayObject([]);
        $code = $error instanceof ProviderTransferError && is_array($error->details) ? ($error->details['details']['code'] ?? null) : null;
        if (is_string($code) && $code !== '') {
            $this->handlers['errors'][$code] ??= [];
            Middleware::runMiddleware($context, $this->handlers['errors'][$code]);
        }

        return !empty($context['ignore']);
    }

    /**
     * Report a fatal error and throw it
     */
    public function panic(\Throwable $error): never
    {
        $this->reportError($error, 'fatal');

        throw $error;
    }

    /**
     * Report an error diagnostic
     */
    public function reportError(\Throwable $error, string $severity): void
    {
        $this->diagnostics->report([
            'kind' => 'error',
            'details' => [
                'severity' => $severity,
                'createdAt' => Diagnostic::now(),
                'name' => self::errorName($error),
                'message' => $error->getMessage(),
                'error' => $error,
            ],
        ]);
    }

    /**
     * Report a warning diagnostic
     */
    public function reportWarning(string $message, ?string $origin = null): void
    {
        $details = ['createdAt' => Diagnostic::now(), 'message' => $message];
        if ($origin !== null) {
            $details['origin'] = $origin;
        }

        $this->diagnostics->report(['kind' => 'warning', 'details' => $details]);
    }

    /**
     * Report an info diagnostic
     */
    public function reportInfo(string $message, mixed $params = null): void
    {
        $details = ['createdAt' => Diagnostic::now(), 'message' => $message, 'origin' => 'engine'];
        if ($params !== null) {
            $details['params'] = $params;
        }

        $this->diagnostics->report(['kind' => 'info', 'details' => $details]);
    }

    /** A provider's `name` (a public property of every provider). */
    public static function providerName(IProvider $provider): string
    {
        return property_exists($provider, 'name') && is_string($provider->name) ? $provider->name : '';
    }

    /** The JS `error.name` of an exception. */
    public static function errorName(\Throwable $error): string
    {
        if (property_exists($error, 'name') && is_string($error->name)) {
            return $error->name;
        }

        return 'Error';
    }

    /**
     * Create and return a transform based on the given stage and options.
     *
     * Allowed transformations includes 'filter' and 'map'.
     *
     * @param array{includeGlobal?: bool} $options
     *
     * @return \Closure(mixed): list<mixed>
     */
    private function createStageTransformStream(string $key, array $options = []): \Closure
    {
        $includeGlobal = $options['includeGlobal'] ?? true;
        $throttle = $this->options['throttle'] ?? null;
        $transforms = $this->options['transforms'] ?? [];
        $globalTransforms = $transforms['global'] ?? [];
        $stageTransforms = $transforms[$key] ?? [];

        $chain = [];

        $applyTransforms = static function (array $list) use (&$chain): void {
            foreach ($list as $transform) {
                if (isset($transform['filter']) && is_callable($transform['filter'])) {
                    $chain[] = StreamUtils::filter($transform['filter']);
                }

                if (isset($transform['map']) && is_callable($transform['map'])) {
                    $chain[] = StreamUtils::map($transform['map']);
                }
            }
        };

        if ($includeGlobal) {
            $applyTransforms($globalTransforms);
        }

        if (is_numeric($throttle) && $throttle > 0) {
            $chain[] = static function (mixed $data) use ($throttle): array {
                usleep((int) ($throttle * 1000));

                return [$data];
            };
        }

        $applyTransforms($stageTransforms);

        return StreamUtils::chain($chain);
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * Update the Engine's transfer progress data for a given stage.
     *
     * Providing aggregate options enable custom computation to get the size (bytes) or the aggregate key associated with the data
     *
     * @param array{size?: callable(mixed): int, key?: callable(mixed): ?string} $aggregate
     */
    private function updateTransferProgress(string $stage, mixed $data, array $aggregate = []): void
    {
        if (!isset($this->progress->data[$stage])) {
            $this->progress->data[$stage] = ['count' => 0, 'bytes' => 0, 'startTime' => self::nowMs()];
        }

        $size = isset($aggregate['size']) ? $aggregate['size']($data) : self::jsonLength($data);
        $key = isset($aggregate['key']) ? $aggregate['key']($data) : null;

        $this->progress->data[$stage]['count'] += 1;
        $this->progress->data[$stage]['bytes'] += $size;

        // Handle aggregate updates if necessary
        if ($key !== null && $key !== '') {
            $this->progress->data[$stage]['aggregates'] ??= [];
            $this->progress->data[$stage]['aggregates'][$key] ??= ['count' => 0, 'bytes' => 0];
            $this->progress->data[$stage]['aggregates'][$key]['count'] += 1;
            $this->progress->data[$stage]['aggregates'][$key]['bytes'] += $size;
        }
    }

    /** `JSON.stringify(data).length` (UTF-16 code units). */
    private static function jsonLength(mixed $data): int
    {
        try {
            $json = Json::stringify($data);
        } catch (\JsonException) {
            return 0;
        }

        return (int) (mb_strlen($json, 'UTF-8') + (preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $json) ?: 0));
    }

    /**
     * Per-object progress tracking: updates the Engine's transfer progress data and triggers
     * stage update events for each chunk.
     *
     * @param array{size?: callable(mixed): int, key?: callable(mixed): ?string} $aggregate
     *
     * @return \Closure(mixed): list<mixed>
     */
    private function progressTracker(string $stage, array $aggregate = []): \Closure
    {
        return function (mixed $data) use ($stage, $aggregate): array {
            $this->updateTransferProgress($stage, $data, $aggregate);
            $this->emitStageUpdate('progress', $stage);

            return [$data];
        };
    }

    /**
     * Per-chunk progress tracking (used for assets): each asset's byte stream is wrapped so that
     * bytes are counted as the destination reads them, and the asset counted once it is read.
     *
     * @param array{key?: callable(mixed): ?string} $aggregate
     *
     * @return \Closure(mixed): list<mixed>
     */
    private function progressTrackerChunks(string $stage, array $aggregate = []): \Closure
    {
        return function (mixed $asset) use ($stage, $aggregate): array {
            if (!is_array($asset) || !isset($asset['stream']) || !is_iterable($asset['stream']) || $asset['stream'] instanceof PassThrough) {
                return [$asset];
            }

            $key = isset($aggregate['key']) ? $aggregate['key']($asset) : null;
            if (!isset($this->progress->data[$stage])) {
                $this->progress->data[$stage] = ['count' => 0, 'bytes' => 0, 'startTime' => self::nowMs()];
            }

            $inner = $asset['stream'];
            $asset['stream'] = (function () use ($inner, $stage, $key): \Generator {
                foreach ($inner as $chunk) {
                    // Asset file reads should yield bytes; avoid skewing totals if not.
                    $byteLength = is_string($chunk) ? strlen($chunk) : 1;
                    $this->updateStageProgress($stage, $key, 0, $byteLength);
                    $this->emitStageUpdate('progress', $stage);
                    yield $chunk;
                }

                $this->updateStageProgress($stage, $key, 1, 0);
                $this->emitStageUpdate('progress', $stage);
            })();

            return [$asset];
        };
    }

    /** Add to a stage's count and bytes, and to its aggregate for `$key` (`#updateAggregateBytes` / `#incrementAggregateCount`). */
    private function updateStageProgress(string $stage, ?string $key, int $count, int $bytes): void
    {
        $stageProgress = $this->progress->data[$stage] ?? ['count' => 0, 'bytes' => 0, 'startTime' => self::nowMs()];
        $stageProgress['count'] += $count;
        $stageProgress['bytes'] += $bytes;

        if ($key !== null && $key !== '') {
            $aggregates = $stageProgress['aggregates'] ?? [];
            $aggregate = $aggregates[$key] ?? ['count' => 0, 'bytes' => 0];
            $aggregate['count'] += $count;
            $aggregate['bytes'] += $bytes;
            $aggregates[$key] = $aggregate;
            $stageProgress['aggregates'] = $aggregates;
        }

        $this->progress->data[$stage] = $stageProgress;
    }

    /**
     * Shorthand method used to trigger transfer update events to every listeners
     *
     * @param array<string, mixed>|null $payload
     */
    private function emitTransferUpdate(string $type, ?array $payload = null): void
    {
        $this->progress->stream->emit("transfer::{$type}", $payload);
    }

    /**
     * Shorthand method used to trigger stage update events to every listeners
     */
    private function emitStageUpdate(string $type, string $transferStage): void
    {
        $this->progress->stream->emit("stage::{$type}", [
            'data' => $this->progress->data,
            'stage' => $transferStage,
        ]);
    }

    /**
     * Run a version check between two strapi version (source and destination) using the strategy given to the engine during initialization.
     *
     * If there is a mismatch, throws a validation error.
     */
    private function assertStrapiVersionIntegrity(?string $sourceVersion, ?string $destinationVersion): void
    {
        $strategy = ($this->options['versionStrategy'] ?? null) ?: self::DEFAULT_VERSION_STRATEGY;

        $reject = function () use ($strategy, $sourceVersion, $destinationVersion): never {
            throw new TransferEngineValidationError(
                "The source and destination provide are targeting incompatible Strapi versions (using the \"{$strategy}\" strategy). The source (" . self::providerName($this->sourceProvider) . ") version is " . ($sourceVersion ?? 'undefined') . " and the destination (" . self::providerName($this->destinationProvider) . ") version is " . ($destinationVersion ?? 'undefined'),
                [
                    'check' => 'strapi.version',
                    'strategy' => $strategy,
                    'versions' => ['source' => $sourceVersion, 'destination' => $destinationVersion],
                ]
            );
        };

        if (!$sourceVersion || !$destinationVersion || $strategy === 'ignore' || $destinationVersion === $sourceVersion) {
            return;
        }

        $diff = null;
        try {
            $diff = self::semverDiff($sourceVersion, $destinationVersion);
        } catch (\InvalidArgumentException) {
            $reject();
        }

        if ($diff === null) {
            return;
        }

        // upstream's list (with its 'prelease' typo: a prerelease difference never passes 'patch')
        $validPatch = ['prelease', 'build'];
        $validMinor = [...$validPatch, 'patch', 'prepatch'];
        $validMajor = [...$validMinor, 'minor', 'preminor'];
        if ($strategy === 'patch' && in_array($diff, $validPatch, true)) {
            return;
        }
        if ($strategy === 'minor' && in_array($diff, $validMinor, true)) {
            return;
        }
        if ($strategy === 'major' && in_array($diff, $validMajor, true)) {
            return;
        }

        $reject();
    }

    /**
     * semver `diff(v1, v2)`: `null` when equal, else `major`, `premajor`, `minor`, `preminor`,
     * `patch`, `prepatch` or `prerelease`. Throws on an invalid version.
     */
    public static function semverDiff(string $version1, string $version2): ?string
    {
        $v1 = self::semverParse($version1);
        $v2 = self::semverParse($version2);

        $comparison = self::semverCompare($v1, $v2);
        if ($comparison === 0) {
            return null;
        }

        $v1Higher = $comparison > 0;
        $high = $v1Higher ? $v1 : $v2;
        $low = $v1Higher ? $v2 : $v1;
        $highHasPre = $high['prerelease'] !== [];
        $lowHasPre = $low['prerelease'] !== [];

        if ($lowHasPre && !$highHasPre) {
            if (!$low['patch'] && !$low['minor']) {
                return 'major';
            }
            if (self::compareMain($low, $high) === 0) {
                if ($low['minor'] && !$low['patch']) {
                    return 'minor';
                }

                return 'patch';
            }
        }

        $prefix = $highHasPre ? 'pre' : '';
        if ($v1['major'] !== $v2['major']) {
            return $prefix . 'major';
        }
        if ($v1['minor'] !== $v2['minor']) {
            return $prefix . 'minor';
        }
        if ($v1['patch'] !== $v2['patch']) {
            return $prefix . 'patch';
        }

        return 'prerelease';
    }

    /** @return array{major: int, minor: int, patch: int, prerelease: list<string>} */
    private static function semverParse(string $version): array
    {
        if (preg_match('/^\s*[v=]*\s*(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-?((?:[0-9]+|\d*[a-zA-Z-][a-zA-Z0-9-]*)(?:\.(?:[0-9]+|\d*[a-zA-Z-][a-zA-Z0-9-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?\s*$/', $version, $m) !== 1) {
            throw new \InvalidArgumentException("Invalid Version: {$version}");
        }

        return [
            'major' => (int) $m[1],
            'minor' => (int) $m[2],
            'patch' => (int) $m[3],
            'prerelease' => isset($m[4]) && $m[4] !== '' ? explode('.', $m[4]) : [],
        ];
    }

    /**
     * @param array{major: int, minor: int, patch: int, prerelease: list<string>} $a
     * @param array{major: int, minor: int, patch: int, prerelease: list<string>} $b
     */
    private static function compareMain(array $a, array $b): int
    {
        return [$a['major'], $a['minor'], $a['patch']] <=> [$b['major'], $b['minor'], $b['patch']];
    }

    /**
     * @param array{major: int, minor: int, patch: int, prerelease: list<string>} $a
     * @param array{major: int, minor: int, patch: int, prerelease: list<string>} $b
     */
    private static function semverCompare(array $a, array $b): int
    {
        $main = self::compareMain($a, $b);
        if ($main !== 0) {
            return $main;
        }

        if ($a['prerelease'] !== [] && $b['prerelease'] === []) {
            return -1;
        }
        if ($a['prerelease'] === [] && $b['prerelease'] !== []) {
            return 1;
        }

        $length = max(count($a['prerelease']), count($b['prerelease']));
        for ($i = 0; $i < $length; ++$i) {
            $x = $a['prerelease'][$i] ?? null;
            $y = $b['prerelease'][$i] ?? null;
            if ($x === null) {
                return -1;
            }
            if ($y === null) {
                return 1;
            }
            if ($x === $y) {
                continue;
            }
            $xNum = ctype_digit($x);
            $yNum = ctype_digit($y);
            if ($xNum && $yNum) {
                return (int) $x <=> (int) $y;
            }
            if ($xNum) {
                return -1;
            }
            if ($yNum) {
                return 1;
            }

            return strcmp($x, $y) <=> 0;
        }

        return 0;
    }

    /**
     * Run a check between two set of schemas (source and destination) using the strategy given to the engine during initialization.
     *
     * If there are differences and/or incompatibilities between source and destination schemas, then throw a validation error.
     *
     * @param array<string, mixed> $sourceSchemas
     * @param array<string, mixed> $destinationSchemas
     */
    private function assertSchemasMatching(array $sourceSchemas, array $destinationSchemas): void
    {
        $strategy = ($this->options['schemaStrategy'] ?? null) ?: self::DEFAULT_SCHEMA_STRATEGY;

        if ($strategy === 'ignore') {
            return;
        }

        $keys = array_keys($sourceSchemas);
        foreach (array_keys($destinationSchemas) as $key) {
            if (!array_key_exists($key, $sourceSchemas)) {
                $keys[] = $key;
            }
        }

        $diffs = [];
        foreach ($keys as $key) {
            $sourceSchema = array_key_exists($key, $sourceSchemas) ? $sourceSchemas[$key] : Json::UNDEFINED;
            $destinationSchema = array_key_exists($key, $destinationSchemas) ? $destinationSchemas[$key] : Json::UNDEFINED;
            $schemaDiffs = Schemas::compareSchemas($sourceSchema, $destinationSchema, $strategy);

            if ($schemaDiffs !== []) {
                $diffs[(string) $key] = $schemaDiffs;
            }
        }

        if ($diffs !== []) {
            $formatted = [];
            foreach ($diffs as $uid => $ctDiffs) {
                $msg = "- {$uid}:" . PHP_EOL;

                // sort by kind, descending (upstream: (a, b) => (a.kind > b.kind ? -1 : 1))
                usort($ctDiffs, static fn (array $a, array $b): int => $a['kind'] > $b['kind'] ? -1 : 1);

                $lines = [];
                foreach ($ctDiffs as $diff) {
                    $path = implode('.', $diff['path']);

                    if ($diff['kind'] === 'added') {
                        $line = "{$path} exists in destination schema but not in source schema and the data will not be transferred.";
                    } elseif ($diff['kind'] === 'deleted') {
                        $line = "{$path} exists in source schema but not in destination schema and the data will not be transferred.";
                    } elseif ($diff['kind'] === 'modified') {
                        $types = $diff['types'] ?? ['', ''];
                        $values = $diff['values'] ?? [null, null];
                        $line = $types[0] === $types[1]
                            ? "Schema value changed at \"{$path}\": \"" . self::jsString($values[0]) . "\" ({$types[0]}) => \"" . self::jsString($values[1]) . "\" ({$types[1]})"
                            : "Schema has differing data types at \"{$path}\": \"" . self::jsString($values[0]) . "\" ({$types[0]}) => \"" . self::jsString($values[1]) . "\" ({$types[1]})";
                    } else {
                        throw new TransferEngineValidationError("Invalid diff found for \"{$uid}\"", ['check' => "schema on {$uid}"]);
                    }

                    $lines[] = "  - {$line}";
                }

                $formatted[] = $msg . implode(PHP_EOL, $lines);
            }

            throw new TransferEngineValidationError(
                "Invalid schema changes detected during integrity checks (using the {$strategy} strategy). Please find a summary of the changes below:\n" . implode(PHP_EOL, $formatted),
                [
                    'check' => 'schema.changes',
                    'strategy' => $strategy,
                    'diffs' => $diffs,
                ]
            );
        }
    }

    /** A value interpolated in a JS template string. */
    private static function jsString(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === Json::UNDEFINED => 'undefined',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => array_is_list($value) ? implode(',', array_map(self::jsString(...), $value)) : '[object Object]',
            is_scalar($value) => (string) $value,
            default => '[object Object]',
        };
    }

    public function shouldSkipStage(string $stage): bool
    {
        $exclude = $this->options['exclude'] ?? null;
        $only = $this->options['only'] ?? null;

        // schemas must always be included
        if ($stage === 'schemas') {
            return false;
        }

        // everything is included by default unless 'only' has been set
        $included = empty($only);
        if (!empty($only)) {
            $included = false;
            foreach ($only as $transferGroup) {
                if (!empty(self::TRANSFER_GROUP_PRESETS[$transferGroup][$stage])) {
                    $included = true;
                    break;
                }
            }
        }

        if (!empty($exclude) && $included) {
            foreach ($exclude as $transferGroup) {
                if (!empty(self::TRANSFER_GROUP_PRESETS[$transferGroup][$stage])) {
                    $included = false;
                    break;
                }
            }
        }

        return !$included;
    }

    /**
     * @param iterable<mixed>|null              $source
     * @param (\Closure(mixed): list<mixed>)|null $transform
     * @param (\Closure(mixed): list<mixed>)|null $tracker
     */
    private function transferStage(string $stage, ?iterable $source, ?Writable $destination, ?\Closure $transform = null, ?\Closure $tracker = null): void
    {
        if ($this->aborted) {
            throw new TransferEngineError('fatal', 'Transfer aborted.');
        }

        $updateEndTime = function () use ($stage): void {
            if (isset($this->progress->data[$stage])) {
                $this->progress->data[$stage]['endTime'] = self::nowMs();
            }
        };

        if ($source === null || $destination === null || $this->shouldSkipStage($stage)) {
            // Close the source and destination streams
            try {
                if ($destination !== null && !$destination->destroyed) {
                    $destination->destroy();
                }
                if ($source instanceof \Generator) {
                    // let the generator run its `finally` blocks
                    unset($source);
                }
            } catch (\Throwable $error) {
                $this->reportWarning($error->getMessage(), "transfer({$stage})");
            }

            $this->emitStageUpdate('skip', $stage);

            return;
        }

        $this->emitStageUpdate('start', $stage);

        try {
            foreach ($source as $chunk) {
                if ($this->aborted) {
                    throw new TransferEngineError('fatal', 'Transfer aborted.');
                }

                $chunks = $transform !== null ? $transform($chunk) : [$chunk];
                foreach ($chunks as $item) {
                    $tracked = $tracker !== null ? $tracker($item) : [$item];
                    foreach ($tracked as $out) {
                        $destination->write($out);
                    }
                }
            }

            $destination->end();

            $this->emitStageUpdate('finish', $stage);
        } catch (\Throwable $e) {
            $updateEndTime();
            $this->emitStageUpdate('error', $stage);
            $this->reportError($e, 'error');
            if (!$destination->destroyed) {
                try {
                    $destination->destroy($e);
                } catch (\Throwable) {
                    // the original error is the one to report
                }
            }
            throw $e;
        } finally {
            $updateEndTime();
        }
    }

    /** Cause an ongoing transfer to abort gracefully */
    public function abortTransfer(): never
    {
        $this->aborted = true;

        throw new TransferEngineError('fatal', 'Transfer aborted.');
    }

    public function init(): void
    {
        // Resolve providers' resource and store them in the engine's internal state
        $this->resolveProviderResource();

        // Update the destination provider's source metadata
        $sourceMetadata = $this->metadata['source'] ?? null;

        if ($sourceMetadata && method_exists($this->destinationProvider, 'setMetadata')) {
            $this->destinationProvider->setMetadata('source', $sourceMetadata);
        }
    }

    /**
     * Run the bootstrap method in both source and destination providers
     */
    public function bootstrap(): void
    {
        $errors = [];
        foreach ([$this->sourceProvider, $this->destinationProvider] as $provider) {
            try {
                if (method_exists($provider, 'bootstrap')) {
                    $provider->bootstrap($this->diagnostics);
                }
            } catch (\Throwable $error) {
                $errors[] = $error;
            }
        }

        foreach ($errors as $error) {
            $this->panic($error);
        }
    }

    /**
     * Run the close method in both source and destination providers
     */
    public function close(): void
    {
        $this->closed = true;

        $errors = [];
        foreach ([$this->sourceProvider, $this->destinationProvider] as $provider) {
            try {
                if (method_exists($provider, 'close')) {
                    $provider->close();
                }
            } catch (\Throwable $error) {
                $errors[] = $error;
            }
        }

        foreach ($errors as $error) {
            $this->panic($error);
        }
    }

    /**
     * Close both providers on a failure path, reporting rather than throwing cleanup errors so the
     * error that caused the failure is the one the caller sees.
     */
    private function closeAfterError(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ([$this->sourceProvider, $this->destinationProvider] as $provider) {
            try {
                if (method_exists($provider, 'close')) {
                    $provider->close();
                }
            } catch (\Throwable $error) {
                $message = $error->getMessage();
                $this->reportWarning("Failed to close the " . self::providerName($provider) . " provider" . ($message !== '' ? ": {$message}" : ''), 'transfer(cleanup)');
            }
        }
    }

    private function resolveProviderResource(): void
    {
        $sourceMetadata = $this->sourceProvider->getMetadata();
        $destinationMetadata = $this->destinationProvider->getMetadata();

        if ($sourceMetadata) {
            $this->metadata['source'] = $sourceMetadata;
        }

        if ($destinationMetadata) {
            $this->metadata['destination'] = $destinationMetadata;
        }
    }

    /** @return array{sourceSchemas: array<string, mixed>|null, destinationSchemas: array<string, mixed>|null} */
    private function getSchemas(): array
    {
        if (!array_key_exists('source', $this->schema) || $this->schema['source'] === null) {
            $this->schema['source'] = method_exists($this->sourceProvider, 'getSchemas') ? $this->sourceProvider->getSchemas() : null;
        }

        if (!array_key_exists('destination', $this->schema) || $this->schema['destination'] === null) {
            $this->schema['destination'] = method_exists($this->destinationProvider, 'getSchemas') ? $this->destinationProvider->getSchemas() : null;
        }

        return [
            'sourceSchemas' => $this->schema['source'],
            'destinationSchemas' => $this->schema['destination'],
        ];
    }

    public function integrityCheck(): void
    {
        $sourceMetadata = $this->sourceProvider->getMetadata();
        $destinationMetadata = $this->destinationProvider->getMetadata();

        if ($sourceMetadata && $destinationMetadata) {
            $sourceVersion = $sourceMetadata['strapi']['version'] ?? null;
            $destinationVersion = $destinationMetadata['strapi']['version'] ?? null;
            $this->assertStrapiVersionIntegrity(
                is_string($sourceVersion) ? $sourceVersion : null,
                is_string($destinationVersion) ? $destinationVersion : null
            );
        }

        ['sourceSchemas' => $sourceSchemas, 'destinationSchemas' => $destinationSchemas] = $this->getSchemas();

        try {
            if ($sourceSchemas && $destinationSchemas) {
                $this->assertSchemasMatching($sourceSchemas, $destinationSchemas);
            }
        } catch (\Throwable $error) {
            // if this is a schema matching error, allow handlers to resolve it
            $schemaDiffs = $error instanceof TransferEngineValidationError && is_array($error->details) ? ($error->details['details']['diffs'] ?? null) : null;
            if (is_array($schemaDiffs) && $schemaDiffs !== []) {
                /** @var \ArrayObject<string, mixed> $context */
                $context = new \ArrayObject([
                    'ignoredDiffs' => [],
                    'diffs' => $schemaDiffs,
                    'source' => $this->sourceProvider,
                    'destination' => $this->destinationProvider,
                ]);

                // if we don't have any handlers, throw the original error
                if ($this->handlers['schemaDiff'] === []) {
                    throw $error;
                }

                Middleware::runMiddleware($context, $this->handlers['schemaDiff']);

                // if there are any remaining diffs that weren't ignored
                $unresolvedDiffs = Json::diff($context['diffs'], $context['ignoredDiffs']);
                if ($unresolvedDiffs !== []) {
                    $this->panic(new TransferEngineValidationError('Unresolved differences in schema', [
                        'check' => 'schema.changes',
                        'unresolvedDiffs' => $unresolvedDiffs,
                    ]));
                }

                return;
            }

            throw $error;
        }
    }

    /**
     * @return array{source: mixed, destination: mixed, engine: array<string, StageProgress>}
     */
    public function transfer(): array
    {
        // reset data between transfers
        $this->progress->data = [];
        $this->closed = false;

        try {
            $this->emitTransferUpdate('init');
            $this->bootstrap();
            $this->init();

            $this->integrityCheck();
            $this->validateStages();

            $this->emitTransferUpdate('start');

            $this->beforeTransfer();

            // Run the transfer stages
            $this->transferSchemas();
            $this->transferEntities();
            $this->transferAssets();
            $this->transferLinks();
            $this->transferConfiguration();
            // Gracefully close the providers
            $this->close();

            $this->emitTransferUpdate('finish');
        } catch (\Throwable $e) {
            $this->emitTransferUpdate('error', ['error' => $e]);

            $items = $this->diagnostics->items();
            $lastDiagnostic = $items === [] ? null : $items[array_key_last($items)];
            // Do not report an error diagnostic if the last one reported the same error
            if ($lastDiagnostic === null || $lastDiagnostic['kind'] !== 'error' || ($lastDiagnostic['details']['error'] ?? null) !== $e) {
                $this->reportError($e, $e instanceof DataTransferError ? $e->severity : 'fatal');
            }

            // Rollback the destination provider if an exception is thrown before providers are closed.
            // Once close has started, a provider's transaction may already have ended and cannot be
            // rolled back safely. Note: This will be configurable in the future.
            if (!$this->closed) {
                try {
                    if (method_exists($this->destinationProvider, 'rollback')) {
                        $this->destinationProvider->rollback($e);
                    }
                } catch (\Throwable $rollbackError) {
                    $message = $rollbackError->getMessage();

                    $this->reportWarning("Failed to rollback the " . self::providerName($this->destinationProvider) . " provider" . ($message !== '' ? ": {$message}" : ''), 'transfer(rollback)');
                }
            }

            // Providers bootstrapped before the failure may still hold resources: the local providers
            // disable database lifecycles on bootstrap and only re-enable them on close, so skipping this
            // would leave a programmatic caller's Strapi instance with lifecycles permanently off.
            $this->closeAfterError();

            throw $e;
        }

        return [
            'source' => property_exists($this->sourceProvider, 'results') ? $this->sourceProvider->results : null,
            'destination' => property_exists($this->destinationProvider, 'results') ? $this->destinationProvider->results : null,
            'engine' => $this->progress->data,
        ];
    }

    public function validateStages(): void
    {
        if (!method_exists($this->sourceProvider, 'validateStage')) {
            return;
        }

        foreach (self::TRANSFER_STAGES as $stage) {
            if (!$this->shouldSkipStage($stage)) {
                $this->sourceProvider->validateStage($stage);
            }
        }
    }

    public function beforeTransfer(): void
    {
        $runWithDiagnostic = function (IProvider $provider): void {
            try {
                if (method_exists($provider, 'beforeTransfer')) {
                    $provider->beforeTransfer();
                }
            } catch (\Throwable $error) {
                $resolved = $this->attemptResolveError($error);

                if ($resolved) {
                    return;
                }
                $this->panic($error);
            }
        };

        $runWithDiagnostic($this->sourceProvider);
        $runWithDiagnostic($this->destinationProvider);
    }

    /** @return iterable<mixed>|null */
    private function readStream(string $method): ?iterable
    {
        if (!method_exists($this->sourceProvider, $method)) {
            return null;
        }

        $stream = $this->sourceProvider->{$method}();

        return is_iterable($stream) ? $stream : null;
    }

    private function writeStream(string $method): ?Writable
    {
        if (!method_exists($this->destinationProvider, $method)) {
            return null;
        }

        $stream = $this->destinationProvider->{$method}();

        return $stream instanceof Writable ? $stream : null;
    }

    public function transferSchemas(): void
    {
        $stage = 'schemas';
        if ($this->shouldSkipStage($stage)) {
            return;
        }

        $source = $this->readStream('createSchemasReadStream');
        $destination = $this->writeStream('createSchemasWriteStream');

        $transform = $this->createStageTransformStream($stage);
        $tracker = $this->progressTracker($stage, [
            'key' => static fn (mixed $value): ?string => is_array($value) && is_string($value['modelType'] ?? null) ? $value['modelType'] : null,
        ]);

        $this->transferStage($stage, $source, $destination, $transform, $tracker);
    }

    public function transferEntities(): void
    {
        $stage = 'entities';
        if ($this->shouldSkipStage($stage)) {
            return;
        }

        $source = $this->readStream('createEntitiesReadStream');
        $destination = $this->writeStream('createEntitiesWriteStream');

        $stageTransform = $this->createStageTransformStream($stage);
        $transform = StreamUtils::chain([
            $stageTransform,
            function (mixed $entity): array {
                ['destinationSchemas' => $schemas] = $this->getSchemas();

                if (!$schemas || !is_array($entity)) {
                    return [$entity];
                }

                // TODO: this would be safer if we only ignored things in ignoredDiffs, otherwise continue and let an error be thrown
                $type = $entity['type'] ?? null;
                $schema = is_string($type) ? ($schemas[$type] ?? null) : null;

                // If the type of the transferred entity doesn't exist in the destination, then discard it
                if (!is_array($schema) || ($schema['modelType'] ?? null) !== 'contentType') {
                    return [];
                }

                $attributes = is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];
                $attributesToKeep = [...array_keys($attributes), 'documentId'];
                $data = is_array($entity['data'] ?? null) ? $entity['data'] : [];
                $picked = [];
                // lodash pick keeps the order of the picked paths
                foreach ($attributesToKeep as $attribute) {
                    if (array_key_exists($attribute, $data)) {
                        $picked[$attribute] = $data[$attribute];
                    }
                }
                $entity['data'] = $picked;

                return [$entity];
            },
        ]);

        $tracker = $this->progressTracker($stage, [
            'key' => static fn (mixed $value): ?string => is_array($value) && is_string($value['type'] ?? null) ? $value['type'] : null,
        ]);

        $this->transferStage($stage, $source, $destination, $transform, $tracker);
    }

    public function transferLinks(): void
    {
        $stage = 'links';
        if ($this->shouldSkipStage($stage)) {
            return;
        }

        $source = $this->readStream('createLinksReadStream');
        $destination = $this->writeStream('createLinksWriteStream');

        $transform = StreamUtils::chain([
            $this->createStageTransformStream($stage),
            function (mixed $link): array {
                ['destinationSchemas' => $schemas] = $this->getSchemas();
                if (!$schemas || !is_array($link)) {
                    return [$link];
                }

                // TODO: this would be safer if we only ignored things in ignoredDiffs, otherwise continue and let an error be thrown
                $isValidType = static fn (mixed $uid): bool => (is_string($uid) || is_int($uid)) && array_key_exists($uid, $schemas);

                if (!$isValidType($link['left']['type'] ?? null) || !$isValidType($link['right']['type'] ?? null)) {
                    return []; // ignore the link
                }

                return [$link];
            },
        ]);

        $tracker = $this->progressTracker($stage);

        $this->transferStage($stage, $source, $destination, $transform, $tracker);
    }

    public function transferAssets(): void
    {
        $stage = 'assets';
        if ($this->shouldSkipStage($stage)) {
            return;
        }

        $source = $this->readStream('createAssetsReadStream');
        $destination = $this->writeStream('createAssetsWriteStream');

        $transform = $this->createStageTransformStream($stage);
        $tracker = $this->progressTrackerChunks($stage, [
            'key' => static function (mixed $value): string {
                $filename = is_array($value) && is_string($value['filename'] ?? null) ? $value['filename'] : '';
                $ext = self::extname($filename);

                return $ext !== '' ? $ext : 'No extension';
            },
        ]);

        $this->mergeSourceStageTotals($stage);
        $this->transferStage($stage, $source, $destination, $transform, $tracker);
    }

    /** node `path.extname()` */
    public static function extname(string $path): string
    {
        $base = basename(str_replace('\\', '/', $path));
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === 0) {
            return '';
        }

        return substr($base, $dot);
    }

    /**
     * Merge optional source-reported totals into progress before the stage starts (CLI ETA / totals).
     */
    private function mergeSourceStageTotals(string $stage): void
    {
        if (!method_exists($this->sourceProvider, 'getStageTotals')) {
            return;
        }

        $totals = $this->sourceProvider->getStageTotals($stage);
        if (!is_array($totals) || (!isset($totals['totalBytes']) && !isset($totals['totalCount']))) {
            return;
        }

        if (!isset($this->progress->data[$stage])) {
            $this->progress->data[$stage] = ['count' => 0, 'bytes' => 0, 'startTime' => self::nowMs()];
        }
        if (isset($totals['totalBytes'])) {
            $this->progress->data[$stage]['totalBytes'] = (int) $totals['totalBytes'];
        }
        if (isset($totals['totalCount'])) {
            $this->progress->data[$stage]['totalCount'] = (int) $totals['totalCount'];
        }
    }

    public function transferConfiguration(): void
    {
        $stage = 'configuration';
        if ($this->shouldSkipStage($stage)) {
            return;
        }

        $source = $this->readStream('createConfigurationReadStream');
        $destination = $this->writeStream('createConfigurationWriteStream');

        $transform = $this->createStageTransformStream($stage);
        $tracker = $this->progressTracker($stage);

        $this->transferStage($stage, $source, $destination, $transform, $tracker);
    }
}
