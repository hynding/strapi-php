<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalDestination;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Errors\Providers\ProviderInitializationError;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Errors\Providers\ProviderValidationError;
use Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore\Configuration as RestoreConfiguration;
use Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore\Entities as RestoreEntities;
use Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore\Links as RestoreLinks;
use Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore\Restore;
use Strapi\DataTransfer\Strapi\Queries\Stream as QueryStream;
use Strapi\DataTransfer\Strapi\Utils\UploadProvider;
use Strapi\DataTransfer\Types\Providers\IDestinationProvider;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Providers;
use Strapi\DataTransfer\Utils\Schema;
use Strapi\DataTransfer\Utils\Stream\Writable;
use Strapi\DataTransfer\Utils\Transaction;

/**
 * Port of src/strapi/providers/local-destination/index.ts: `createLocalStrapiDestinationProvider()`,
 * the destination that restores into a Strapi instance (`restore` strategy: the targeted data is
 * deleted first, everything runs in one database transaction).
 *
 * Options: `getStrapi`, `autoDestroy` (default true), `strategy` (`restore`), `restore`
 * (see {@see Restore}), `onTransferPhase` (callable receiving progress messages during beforeTransfer).
 *
 * @phpstan-import-type RestoreOptions from Restore
 *
 * @phpstan-type LocalDestinationOptions array{getStrapi: callable(): ?Strapi, autoDestroy?: bool|null, restore?: RestoreOptions|null, strategy: string, onTransferPhase?: (callable(string): void)|null}
 */
final class LocalDestination implements IDestinationProvider
{
    public const array VALID_CONFLICT_STRATEGIES = ['restore'];

    public const string DEFAULT_CONFLICT_STRATEGY = 'restore';

    public string $name = 'destination::local-strapi';

    public string $type = 'destination';

    /** @var LocalDestinationOptions */
    public array $options;

    public ?Strapi $strapi = null;

    public ?Transaction $transaction = null;

    public string $uploadsBackupDirectoryName;

    /** @var (\Closure(string): void)|null */
    public ?\Closure $onWarning = null;

    /** @var array<string, mixed>|null */
    public ?array $results = null;

    private ?Diagnostic $diagnostics = null;

    /**
     * The entities mapper is used to map old entities to their new IDs
     *
     * @var array<string, array<int, int>>
     */
    private array $entitiesMapper = [];

    /** @param LocalDestinationOptions $options */
    public function __construct(array $options)
    {
        $this->options = $options;
        $this->uploadsBackupDirectoryName = 'uploads_backup_' . (int) floor(microtime(true) * 1000);
    }

    /** @param LocalDestinationOptions $options */
    public static function createLocalStrapiDestinationProvider(array $options): self
    {
        return new self($options);
    }

    /** `assertValidStrapi(this.strapi, msg)`, returning the instance. */
    private function requireStrapi(string $msg = ''): Strapi
    {
        $strapi = $this->strapi;
        Providers::assertValidStrapi($strapi, $msg);

        return $strapi;
    }

    public function bootstrap(?Diagnostic $diagnostics = null): void
    {
        $this->diagnostics = $diagnostics;
        $this->validateOptions();
        $this->strapi = ($this->options['getStrapi'])();
        if ($this->strapi === null) {
            throw new ProviderInitializationError('Could not access local strapi');
        }
        $this->strapi->db()->lifecycles->disable();
        $this->transaction = Transaction::createTransaction($this->strapi);
    }

    /** TODO: either move this to restore strategy, or restore strategy should given access to these instead of repeating the logic possibly in a different way */
    private function areAssetsIncluded(): bool
    {
        return (bool) ($this->options['restore']['assets'] ?? false);
    }

    private function isContentTypeIncluded(string $type): bool
    {
        $include = $this->options['restore']['entities']['include'] ?? null;
        $exclude = $this->options['restore']['entities']['exclude'] ?? null;

        $notIncluded = $include !== null && !in_array($type, $include, true);
        $excluded = $exclude !== null && in_array($type, $exclude, true);

        return !$excluded && !$notIncluded;
    }

    private function reportInfo(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'local-destination-provider',
            ],
            'kind' => 'info',
        ]);
    }

    public function close(): void
    {
        $autoDestroy = $this->options['autoDestroy'] ?? null;
        $strapi = $this->requireStrapi();
        $this->transaction?->end();
        $strapi->db()->lifecycles->enable();
        // Basically `!== false` but more deterministic
        if ($autoDestroy === null || $autoDestroy === true) {
            $strapi->destroy();
        }
    }

    private function validateOptions(): void
    {
        $this->reportInfo('validating options');
        if (!in_array($this->options['strategy'], self::VALID_CONFLICT_STRATEGIES, true)) {
            throw new ProviderValidationError("Invalid strategy {$this->options['strategy']}", [
                'check' => 'strategy',
                'strategy' => $this->options['strategy'],
                'validStrategies' => self::VALID_CONFLICT_STRATEGIES,
            ]);
        }

        // require restore options when using restore (the only strategy, validated above)
        if (!isset($this->options['restore'])) {
            throw new ProviderValidationError('Missing restore options');
        }
    }

    /** @return array<string, mixed> */
    private function deleteFromRestoreOptions(): array
    {
        $strapi = $this->requireStrapi();
        $restore = $this->options['restore'] ?? null;
        if ($restore === null) {
            throw new ProviderValidationError('Missing restore options');
        }
        $this->reportInfo('deleting record ');

        return Restore::deleteRecords($strapi, $restore);
    }

    private function deleteAllAssets(): void
    {
        $strapi = $this->requireStrapi();
        // if we're not restoring files, don't touch the files
        if (!$this->areAssetsIncluded()) {
            return;
        }

        $this->reportInfo('deleting all assets');

        // TODO use bulk delete when exists in providers
        foreach (QueryStream::rows($strapi, 'plugin::upload.file') as $file) {
            UploadProvider::call($strapi, 'delete', $file);
            if (is_array($file['formats'] ?? null)) {
                foreach ($file['formats'] as $fileFormat) {
                    if (is_array($fileFormat)) {
                        UploadProvider::call($strapi, 'delete', $fileFormat);
                    }
                }
            }
        }

        $this->reportInfo('deleted all assets');
    }

    public function rollback(?\Throwable $e = null): void
    {
        $this->reportInfo('Rolling back transaction');
        $this->transaction?->rollback();
        $this->reportInfo('Rolled back transaction');
    }

    public function beforeTransfer(): void
    {
        if ($this->strapi === null) {
            throw new \RuntimeException('Strapi instance not found');
        }

        $onTransferPhase = $this->options['onTransferPhase'] ?? null;
        $phase = static function (string $message) use ($onTransferPhase): void {
            if ($onTransferPhase !== null) {
                $onTransferPhase($message);
            }
        };

        $phase('Local: preparing destination for restore…');

        $this->transaction?->attach(function () use ($phase): void {
            try {
                if ($this->options['strategy'] === 'restore') {
                    if ($this->areAssetsIncluded()) {
                        $phase('Local: backing up existing upload folder…');
                    }
                    $this->handleAssetsBackup();
                    if ($this->areAssetsIncluded()) {
                        $phase('Local: deleting existing media files from disk…');
                    }
                    $this->deleteAllAssets();
                    $phase('Local: clearing database content for restore…');
                    $this->deleteFromRestoreOptions();
                }
            } catch (\Throwable $error) {
                // upstream: `throw new Error(`restore failed ${error}`)` (this also wraps the
                // ASSETS_DIRECTORY_ERR provider error, so its code no longer reaches the engine)
                throw new \RuntimeException('restore failed ' . self::errorToString($error), 0, $error);
            }
        });
    }

    /** `${error}` */
    private static function errorToString(\Throwable $error): string
    {
        $name = property_exists($error, 'name') && is_string($error->name) ? $error->name : 'Error';

        return $error->getMessage() !== '' ? "{$name}: {$error->getMessage()}" : $name;
    }

    /** @return array{createdAt: string, strapi: array{version: string}} */
    public function getMetadata(): array
    {
        $this->reportInfo('getting metadata');
        $strapi = $this->requireStrapi('Not able to get Schemas');
        $strapiVersion = (string) $strapi->config()->get('info.strapi');
        $createdAt = Diagnostic::now();

        return [
            'createdAt' => $createdAt,
            'strapi' => [
                'version' => $strapiVersion,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function getSchemas(): array
    {
        $this->reportInfo('getting schema');
        $strapi = $this->requireStrapi('Not able to get Schemas');

        $schemas = Schema::schemasToValidJSON([
            ...$strapi->contentTypes(),
            ...$strapi->components(),
        ]);

        return Schema::mapSchemasValues($schemas);
    }

    public function createEntitiesWriteStream(): Writable
    {
        $strapi = $this->requireStrapi('Not able to import entities');
        $this->reportInfo('creating entities stream');
        $strategy = $this->options['strategy'];

        $updateMappingTable = function (string $type, int $oldID, int $newID): void {
            $this->entitiesMapper[$type] ??= [];
            $this->entitiesMapper[$type][$oldID] = $newID;
        };

        if ($strategy === 'restore') {
            return RestoreEntities::createEntitiesWriteStream([
                'strapi' => $strapi,
                'updateMappingTable' => $updateMappingTable,
                'transaction' => $this->transaction,
            ]);
        }

        throw new ProviderValidationError("Invalid strategy {$strategy}", [
            'check' => 'strategy',
            'strategy' => $strategy,
            'validStrategies' => self::VALID_CONFLICT_STRATEGIES,
        ]);
    }

    private function handleAssetsBackup(): ?string
    {
        $strapi = $this->requireStrapi('Not able to create the assets backup');

        // if we're not restoring assets, don't back them up because they won't be touched
        if (!$this->areAssetsIncluded()) {
            return null;
        }

        $config = $strapi->config()->get('plugin::upload');
        if ((is_array($config) ? ($config['provider'] ?? null) : null) === 'local') {
            $this->reportInfo('creating assets backup directory');
            $publicDir = rtrim($strapi->dirs()->public, '/');
            $assetsDirectory = "{$publicDir}/uploads";
            $backupDirectory = "{$publicDir}/{$this->uploadsBackupDirectoryName}";

            try {
                // Check access before attempting to do anything
                if (!is_dir($assetsDirectory) || !is_readable($assetsDirectory) || !is_writable($assetsDirectory)) {
                    throw new \RuntimeException('EACCES');
                }
                if (!is_writable($publicDir) || !is_readable($publicDir)) {
                    throw new \RuntimeException('EACCES');
                }

                if (!@rename($assetsDirectory, $backupDirectory)) {
                    throw new \RuntimeException('rename failed');
                }
                if (!@mkdir($assetsDirectory)) {
                    throw new \RuntimeException('mkdir failed');
                }
                // Create a .gitkeep file to ensure the directory is not empty
                file_put_contents("{$assetsDirectory}/.gitkeep", '');
                $this->reportInfo("created assets backup directory {$backupDirectory}");
            } catch (\Throwable) {
                throw new ProviderTransferError(
                    'The backup folder for the assets could not be created inside the public folder. Please ensure Strapi has write permissions on the public directory',
                    [
                        'code' => 'ASSETS_DIRECTORY_ERR',
                    ]
                );
            }

            return $backupDirectory;
        }

        return null;
    }

    private function removeAssetsBackup(): void
    {
        $strapi = $this->requireStrapi('Not able to remove Assets');
        // if we're not restoring assets, don't back them up because they won't be touched
        if (!$this->areAssetsIncluded()) {
            return;
        }
        // TODO: this should catch all thrown errors and bubble it up to engine so it can be reported as a non-fatal diagnostic message telling the user they may need to manually delete assets
        $config = $strapi->config()->get('plugin::upload');
        if ((is_array($config) ? ($config['provider'] ?? null) : null) === 'local') {
            $this->reportInfo('removing assets backup');
            $backupDirectory = rtrim($strapi->dirs()->public, '/') . "/{$this->uploadsBackupDirectoryName}";
            self::rmrf($backupDirectory);
            $this->reportInfo('successfully removed assets backup');
        }
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                self::rmrf("{$path}/{$item}");
            }
        }
        @rmdir($path);
    }

    /** TODO: Move this logic to the restore strategy */
    public function createAssetsWriteStream(): Writable
    {
        $strapi = $this->requireStrapi('Not able to stream Assets');
        $this->reportInfo('creating assets write stream');
        if (!$this->areAssetsIncluded()) {
            throw new ProviderTransferError('Attempting to transfer assets when `assets` is not set in restore options');
        }

        $transaction = $this->transaction ?? throw new \RuntimeException('Transaction not available for asset upload');

        return AssetsDestinationWritable::createAssetsDestinationWritable([
            'strapi' => $strapi,
            'transaction' => $transaction,
            'resolveUploadFileId' => fn (array $metadata): ?int => isset($metadata['id']) && is_scalar($metadata['id']) ? ($this->entitiesMapper['plugin::upload.file'][(int) $metadata['id']] ?? null) : null,
            'restoreMediaEntitiesContent' => $this->isContentTypeIncluded('plugin::upload.file'),
            'removeAssetsBackup' => fn () => $this->removeAssetsBackup(),
        ]);
    }

    public function createConfigurationWriteStream(): Writable
    {
        $strapi = $this->requireStrapi('Not able to stream Configurations');
        $this->reportInfo('creating configuration write stream');
        $strategy = $this->options['strategy'];

        if ($strategy === 'restore') {
            return RestoreConfiguration::createConfigurationWriteStream($strapi, $this->transaction);
        }

        throw new ProviderValidationError("Invalid strategy {$strategy}", [
            'check' => 'strategy',
            'strategy' => $strategy,
            'validStrategies' => self::VALID_CONFLICT_STRATEGIES,
        ]);
    }

    public function createLinksWriteStream(): Writable
    {
        $this->reportInfo('creating links write stream');
        if ($this->strapi === null) {
            throw new \RuntimeException('Not able to stream links. Strapi instance not found');
        }

        $strategy = $this->options['strategy'];
        $mapID = fn (string $uid, int $id): ?int => $this->entitiesMapper[$uid][$id] ?? null;

        if ($strategy === 'restore') {
            return RestoreLinks::createLinksWriteStream($mapID, $this->strapi, $this->transaction, $this->onWarning);
        }

        throw new ProviderValidationError("Invalid strategy {$strategy}", [
            'check' => 'strategy',
            'strategy' => $strategy,
            'validStrategies' => self::VALID_CONFLICT_STRATEGIES,
        ]);
    }
}
