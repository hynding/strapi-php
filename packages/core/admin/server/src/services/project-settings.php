<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Psr\Http\Message\UploadedFileInterface;
use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of server/src/services/project-settings.ts: the menu and auth logos, uploaded through the
 * upload plugin (`strapi.plugin('upload')`: its `upload` and `image-manipulation` services and its
 * `provider`), called by upstream's names.
 *
 * Upstream receives koa-body's formidable files (`{ filepath, originalFilename, mimetype, size }`);
 * here the request's PSR-7 uploaded files, read through {@see self::toFormidableFile()}. The
 * formatted file's `stream` is a PHP stream resource where upstream has an `fs.ReadStream`.
 */
final class ProjectSettings
{
    public const PROJECT_SETTINGS_FILE_INPUTS = ['menuLogo', 'authLogo'];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * The formidable-like view of an uploaded file (`file.filepath`, `file.originalFilename`,
     * `file.mimetype`, `file.size`), as upstream's controller and validation read it.
     *
     * @return array{filepath: string|null, originalFilename: string|null, mimetype: string|null, size: int|null}|mixed
     */
    public static function toFormidableFile(mixed $file): mixed
    {
        if (is_array($file) && array_is_list($file) && $file !== []) {
            // several files under one field name: koa-body keeps an array, upstream reads `files[inputName]`
            $file = $file[0];
        }

        if (!$file instanceof UploadedFileInterface) {
            return $file;
        }

        $uri = $file->getStream()->getMetadata('uri');

        return [
            'filepath' => is_string($uri) ? $uri : null,
            'originalFilename' => $file->getClientFilename(),
            'mimetype' => $file->getClientMediaType(),
            'size' => $file->getSize(),
        ];
    }

    /**
     * @param array<string, mixed> $files the request files (PSR-7 uploads or formidable-like arrays)
     * @return array<string, array<string, mixed>>
     */
    public function parseFilesData(array $files): array
    {
        $formatedFilesData = [];

        foreach (self::PROJECT_SETTINGS_FILE_INPUTS as $inputName) {
            $file = self::toFormidableFile($files[$inputName] ?? null);

            // Skip empty file inputs
            if (!is_array($file) || $file === []) {
                continue;
            }

            $filepath = is_string($file['filepath'] ?? null) ? $file['filepath'] : '';
            $getStream = static function () use ($filepath) {
                $stream = fopen($filepath, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException("Cannot read {$filepath}");
                }

                return $stream;
            };

            $upload = $this->strapi->plugin('upload');

            // Add formated data for the upload provider
            $formatFileInfo = [$upload->service('upload'), 'formatFileInfo'];
            $getDimensions = [$upload->service('image-manipulation'), 'getDimensions'];
            if (!is_callable($formatFileInfo) || !is_callable($getDimensions)) {
                throw new \RuntimeException('The upload plugin does not expose upload.formatFileInfo() and image-manipulation.getDimensions()');
            }

            $formated = $formatFileInfo([
                'filename' => $file['originalFilename'] ?? null,
                'type' => $file['mimetype'] ?? null,
                'size' => $file['size'] ?? null,
            ]);
            $formated = is_array($formated) ? $formated : (is_object($formated) ? get_object_vars($formated) : []);

            // Add image dimensions
            $dimensions = $getDimensions(['getStream' => $getStream, 'filepath' => $filepath]);
            $formated = [...$formated, ...(is_array($dimensions) ? $dimensions : [])];

            // Add file path, and stream
            $uploadConfig = $this->strapi->config()->get('plugin::upload');
            $formated = [
                ...$formated,
                'stream' => $getStream(),
                'tmpPath' => $filepath,
                // TODO
                'provider' => is_array($uploadConfig) ? ($uploadConfig['provider'] ?? null) : null,
            ];

            $formatedFilesData[$inputName] = $formated;
        }

        return $formatedFilesData;
    }

    /** @return array<string, mixed> */
    public function getProjectSettings(): array
    {
        $store = $this->strapi->store()->scoped(['type' => 'core', 'name' => 'admin']);

        // Returns an object with file inputs names as key and null as value
        $projectSettings = array_fill_keys(self::PROJECT_SETTINGS_FILE_INPUTS, null);
        $stored = $store->get(['key' => 'project-settings']);
        if (is_array($stored)) {
            $projectSettings = [...$projectSettings, ...$stored];
        }

        // Filter file input fields
        foreach (self::PROJECT_SETTINGS_FILE_INPUTS as $inputName) {
            // `!value`: an empty object (a logo field saved without a file) is truthy
            $value = $projectSettings[$inputName];
            if (!is_array($value) && !is_object($value) && empty($value)) {
                continue;
            }

            $picked = Objects::pick(is_array($value) ? $value : (array) $value, ['name', 'url', 'width', 'height', 'ext', 'size']);
            $projectSettings[$inputName] = $picked === [] ? new \stdClass() : $picked;
        }

        return $projectSettings;
    }

    /** Calls a method of the upload provider (an object, or an array of callables). */
    private function callProvider(string $method, mixed ...$args): mixed
    {
        $provider = $this->strapi->plugin('upload')->provider;
        $fn = is_array($provider) ? ($provider[$method] ?? null) : (is_object($provider) ? [$provider, $method] : null);

        if (!is_callable($fn)) {
            throw new \RuntimeException("The upload provider has no {$method}()");
        }

        return $fn(...$args);
    }

    /**
     * Call the provider upload function for each file. The provider fills `url` (and anything
     * else it sets) on the file it is given: here the array comes back by reference.
     *
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private function uploadFiles(array $files = []): array
    {
        foreach ($files as $key => $file) {
            if (!is_array($file) || !is_resource($file['stream'] ?? null)) {
                continue;
            }

            $provider = $this->strapi->plugin('upload')->provider;
            $fn = is_array($provider) ? ($provider['uploadStream'] ?? null) : (is_object($provider) ? [$provider, 'uploadStream'] : null);
            if (!is_callable($fn)) {
                throw new \RuntimeException('The upload provider has no uploadStream()');
            }

            // upstream's provider sets `file.url` on the object it is given: here a mutable
            // \ArrayObject (the upload plugin's file object), or an updated file returned
            $fileObject = new \ArrayObject($file);
            $result = $fn($fileObject);
            $updated = match (true) {
                $result instanceof \ArrayObject => $result->getArrayCopy(),
                is_array($result) => $result,
                default => $fileObject->getArrayCopy(),
            };
            $files[$key] = [...$file, ...$updated];
        }

        return $files;
    }

    /**
     * @param array{previousSettings: mixed, newSettings: array<string, mixed>} $params
     */
    public function deleteOldFiles(array $params): void
    {
        $previousSettings = $params['previousSettings'];
        $newSettings = $params['newSettings'];

        foreach (self::PROJECT_SETTINGS_FILE_INPUTS as $inputName) {
            // Skip if the store doesn't contain project settings
            if (!is_array($previousSettings)) {
                continue;
            }

            // Skip if there was no previous file
            if (empty($previousSettings[$inputName]) || !is_array($previousSettings[$inputName])) {
                continue;
            }

            // Skip if the file was not changed
            if (
                !empty($newSettings[$inputName])
                && is_array($newSettings[$inputName])
                && ($previousSettings[$inputName]['hash'] ?? null) === ($newSettings[$inputName]['hash'] ?? null)
            ) {
                continue;
            }

            // Skip if the file was not uploaded with the current provider
            // TODO
            $uploadConfig = $this->strapi->config()->get('plugin::upload');
            if ((is_array($uploadConfig) ? ($uploadConfig['provider'] ?? null) : null) !== ($previousSettings[$inputName]['provider'] ?? null)) {
                continue;
            }

            // There was a previous file and an new file was uploaded
            // Remove the previous file
            try {
                $this->callProvider('delete', $previousSettings[$inputName]);
            } catch (\Throwable $error) {
                // upstream does not await this promise: a failure never reaches the request
                $this->strapi->log()->error('Failed to delete a previous project logo', ['error' => $error]);
            }
        }
    }

    /**
     * @param array<string, mixed> $newSettings
     * @return array<string, mixed>
     */
    public function updateProjectSettings(array $newSettings): array
    {
        $store = $this->strapi->store()->scoped(['type' => 'core', 'name' => 'admin']);
        $previousSettings = $store->get(['key' => 'project-settings']);
        $files = array_intersect_key($newSettings, array_flip(self::PROJECT_SETTINGS_FILE_INPUTS));

        $uploaded = $this->uploadFiles($files);
        foreach ($uploaded as $key => $file) {
            $newSettings[$key] = $file;
        }

        foreach (self::PROJECT_SETTINGS_FILE_INPUTS as $inputName) {
            // If the user input exists but is not a formdata "file" remove it
            if (array_key_exists($inputName, $newSettings) && $newSettings[$inputName] !== null && !is_array($newSettings[$inputName])) {
                $newSettings[$inputName] = null;
                continue;
            }

            // If the user input is undefined reuse previous setting (do not update field)
            if (empty($newSettings[$inputName]) && $previousSettings) {
                $newSettings[$inputName] = is_array($previousSettings) ? ($previousSettings[$inputName] ?? null) : null;
                continue;
            }

            // Update the file
            $newSettings[$inputName] = Objects::pick(is_array($newSettings[$inputName] ?? null) ? $newSettings[$inputName] : [], [
                'name',
                'hash',
                'url',
                'width',
                'height',
                'ext',
                'size',
                'provider',
            ]);
        }

        // upstream does not await this: run it before the store write, errors are logged
        $this->deleteOldFiles(['previousSettings' => $previousSettings, 'newSettings' => $newSettings]);

        $store->set([
            'key' => 'project-settings',
            'value' => [...(is_array($previousSettings) ? $previousSettings : []), ...$newSettings],
        ]);

        return $this->getProjectSettings();
    }
}
