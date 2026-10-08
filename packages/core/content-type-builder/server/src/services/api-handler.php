<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services;

use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaHandler;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/api-handler.ts: backs up, clears and restores the API folder
 * (`src/api/<apiName>`) of a content type around a schema mutation.
 */
final class ApiHandler
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Deletes the API folder of a contentType.
     *
     * @param array{preserveBackup?: bool} $options
     */
    public function clear(string $uid, array $options = []): void
    {
        $preserveBackup = $options['preserveBackup'] ?? false;
        // TODO double check if this is the correct way to get the apiName
        $contentType = $this->contentType($uid);
        $apiName = $contentType->apiName;

        // Opt out if the content type is not linked to an API (e.g. plugin content type)
        if ($apiName === null || $apiName === '') {
            return;
        }

        $apiFolder = SchemaHandler::join($this->strapi->dirs()->api, $apiName);

        self::recursiveRemoveFiles($apiFolder, self::createDeleteApiFunction($contentType->modelName));
        if (!$preserveBackup) {
            $this->deleteBackup($uid);
        }
    }

    /**
     * Deletes an inactive rollback backup after the enclosing schema mutation has committed.
     * A failed cleanup leaves only housekeeping residue; it does not alter committed schema, API,
     * or groups.json artifacts.
     */
    public function finalize(string $uid): void
    {
        $this->deleteBackup($uid);
    }

    /** Backups the API folder of a contentType. */
    public function backup(string $uid): void
    {
        $apiName = $this->contentType($uid)->apiName;

        // Opt out if the content type is not linked to an API (e.g. plugin content type)
        if ($apiName === null || $apiName === '') {
            return;
        }

        $apiDir = $this->strapi->dirs()->api;
        $apiFolder = SchemaHandler::join($apiDir, $apiName);
        $backupRoot = SchemaHandler::join($apiDir, '.backup');
        $backupFolder = SchemaHandler::join($backupRoot, $apiName);
        $stagingFolder = SchemaHandler::join($backupRoot, ".{$apiName}.staging");

        // Never copy into the canonical backup directly: a retained or failed prior attempt could
        // otherwise merge stale files into the next rollback source.
        self::ensureDir($backupRoot);
        SchemaHandler::remove($stagingFolder);

        try {
            self::copy($apiFolder, $stagingFolder);
        } catch (\Throwable $error) {
            SchemaHandler::remove($stagingFolder);
            throw $error;
        }

        try {
            SchemaHandler::remove($backupFolder);
            self::move($stagingFolder, $backupFolder);
        } catch (\Throwable $error) {
            SchemaHandler::remove($stagingFolder);
            throw $error;
        }
    }

    /** Deletes an API backup folder. */
    private function deleteBackup(string $uid): void
    {
        $apiName = $this->contentType($uid)->apiName;

        // Opt out if the content type is not linked to an API (e.g. plugin content type)
        if ($apiName === null || $apiName === '') {
            return;
        }

        $backupFolder = SchemaHandler::join($this->strapi->dirs()->api, '.backup');
        $apiBackupFolder = SchemaHandler::join($backupFolder, $apiName);

        SchemaHandler::remove($apiBackupFolder);

        $list = self::readdir($backupFolder);
        if ($list === []) {
            SchemaHandler::remove($backupFolder);
        }
    }

    /** Rollbacks the API folder of a contentType. */
    public function rollback(string $uid): void
    {
        $apiName = $this->contentType($uid)->apiName;

        // Opt out if the content type is not linked to an API (e.g. plugin content type)
        if ($apiName === null || $apiName === '') {
            return;
        }

        $apiFolder = SchemaHandler::join($this->strapi->dirs()->api, $apiName);
        $backupFolder = SchemaHandler::join($this->strapi->dirs()->api, '.backup', $apiName);

        if (!file_exists($backupFolder)) {
            throw new \RuntimeException('Cannot rollback api that was not backed up');
        }

        SchemaHandler::remove($apiFolder);
        self::copy($backupFolder, $apiFolder);
        $this->deleteBackup($uid);
    }

    /** Removes an API skeleton created for a new content type before its schema mutation commits. */
    public function clearGenerated(string $apiName): void
    {
        SchemaHandler::remove(SchemaHandler::join($this->strapi->dirs()->api, $apiName));
    }

    /** `strapi.contentTypes[uid]` (upstream destructures it, so an unknown uid throws). */
    private function contentType(string $uid): \Strapi\Types\Schema\Schema
    {
        $contentType = $this->strapi->contentTypes()[$uid] ?? null;
        if ($contentType === null) {
            throw new \TypeError("Cannot destructure property 'apiName' of 'strapi.contentTypes[uid]' as it is undefined.");
        }

        return $contentType;
    }

    /**
     * Creates a delete function to clear an api folder.
     *
     * @return \Closure(string): void
     */
    private static function createDeleteApiFunction(string $baseName): \Closure
    {
        /*
         * Deletes a file in an api.
         * Will only update routes.json instead of deleting it if other routes are present
         */
        return static function (string $filePath) use ($baseName): void {
            $fileName = pathinfo($filePath, PATHINFO_FILENAME);

            $isSchemaFile = str_ends_with($filePath, "{$baseName}/schema.json");
            if ($fileName === $baseName || $isSchemaFile) {
                SchemaHandler::remove($filePath);
            }
        };
    }

    /**
     * Deletes a folder recursively using a delete function.
     *
     * @param \Closure(string): void $deleteFn
     */
    private static function recursiveRemoveFiles(string $folder, \Closure $deleteFn): void
    {
        foreach (self::readdir($folder) as $fileName) {
            $filePath = SchemaHandler::join($folder, $fileName);

            if (is_dir($filePath)) {
                self::recursiveRemoveFiles($filePath, $deleteFn);
            } else {
                $deleteFn($filePath);
            }
        }

        if (self::readdir($folder) === []) {
            SchemaHandler::remove($folder);
        }
    }

    /**
     * `fse.readdir()` (sorted, without `.`/`..`); a missing directory throws like ENOENT.
     *
     * @return list<string>
     */
    private static function readdir(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new \RuntimeException("ENOENT: no such file or directory, scandir '{$dir}'");
        }

        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("EACCES: permission denied, mkdir '{$dir}'");
        }
    }

    /** `fse.copy(src, dest)`: a file or a directory, recursively. */
    private static function copy(string $src, string $dest): void
    {
        if (!file_exists($src)) {
            throw new \RuntimeException("ENOENT: no such file or directory, lstat '{$src}'");
        }

        if (is_dir($src)) {
            self::ensureDir($dest);
            foreach (self::readdir($src) as $entry) {
                self::copy(SchemaHandler::join($src, $entry), SchemaHandler::join($dest, $entry));
            }

            return;
        }

        self::ensureDir(dirname($dest));
        if (!@copy($src, $dest)) {
            throw new \RuntimeException("EACCES: permission denied, copyfile '{$src}' -> '{$dest}'");
        }
    }

    /** `fse.move(src, dest)`. */
    private static function move(string $src, string $dest): void
    {
        self::ensureDir(dirname($dest));
        if (!@rename($src, $dest)) {
            self::copy($src, $dest);
            SchemaHandler::remove($src);
        }
    }
}
