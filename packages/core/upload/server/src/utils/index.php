<?php

declare(strict_types=1);

namespace Strapi\Upload\Utils;

use Psr\Http\Message\UploadedFileInterface;
use Strapi\Core\Strapi;
use Strapi\Upload\Services;

/**
 * Port of server/src/utils/index.ts: `getService(name)` = `strapi.plugin('upload').service(name)`.
 * The conditional return type mirrors the upstream `Services` map for static analysis.
 *
 * PHP-port helpers (no upstream counterpart, they stand in for what JavaScript gives for free):
 *
 * - {@see self::toInputFile()}: a PSR-7 uploaded file as the formidable `File` koa-body hands the
 *   controllers (`filepath`, `originalFilename`, `mimetype`, `size`), an `\ArrayObject` so that
 *   properties set on it later (`tmpWorkingDirectory`, `detectedMimeType`) are seen by every holder,
 *   as on a JavaScript object.
 * - {@see self::requestFiles()}: `ctx.request.files.files` (one file, a list, or null).
 * - {@see self::toPlain()}: what `JSON.stringify` keeps of a file object (functions, streams and
 *   `\ArrayObject` wrappers dropped), applied before a file is persisted.
 */
final class Utils
{
    /**
     * @return ($name is 'upload' ? Services\Upload : ($name is 'image-manipulation' ? Services\ImageManipulation : ($name is 'provider' ? Services\Provider : ($name is 'folder' ? Services\Folder : ($name is 'file' ? Services\File : ($name is 'weeklyMetrics' ? Services\WeeklyMetrics : ($name is 'metrics' ? Services\Metrics : ($name is 'api-upload-folder' ? Services\ApiUploadFolder : ($name is 'extensions' ? Services\Extensions\Extensions : ($name is 'aiMetadata' ? Services\AiMetadata : ($name is 'aiMetadataJobs' ? Services\AiMetadataJobs : ($name is 'aiMetadataProvider' ? Services\AiMetadataProvider : object))))))))))))
     */
    public static function getService(string $name, Strapi $strapi): object
    {
        return $strapi->plugin('upload')->service($name);
    }

    /** `strapi.service('admin::permission')` */
    public static function permissionService(Strapi $strapi): \Strapi\Admin\Services\Permission
    {
        $service = $strapi->service('admin::permission');
        if (!$service instanceof \Strapi\Admin\Services\Permission) {
            throw new \RuntimeException('The admin::permission service is not available');
        }

        return $service;
    }

    /** `strapi.service('admin::user')` */
    public static function adminUserService(Strapi $strapi): \Strapi\Admin\Services\User
    {
        $service = $strapi->service('admin::user');
        if (!$service instanceof \Strapi\Admin\Services\User) {
            throw new \RuntimeException('The admin::user service is not available');
        }

        return $service;
    }

    /** @return \ArrayObject<string, mixed> */
    public static function toInputFile(UploadedFileInterface $file): \ArrayObject
    {
        $uri = null;
        try {
            $uri = $file->getStream()->getMetadata('uri');
        } catch (\Throwable) {
            // stream already moved
        }

        return new \ArrayObject([
            'filepath' => is_string($uri) ? $uri : null,
            'originalFilename' => $file->getClientFilename(),
            'mimetype' => $file->getClientMediaType(),
            'size' => (int) $file->getSize(),
        ]);
    }

    /**
     * `ctx.request.files.files`: one file, a list of files (the field was repeated) or null.
     *
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files
     * @return \ArrayObject<string, mixed>|list<\ArrayObject<string, mixed>>|null
     */
    public static function requestFiles(array $files, string $field = 'files'): \ArrayObject|array|null
    {
        $input = $files[$field] ?? null;
        if ($input === null) {
            return null;
        }
        if (is_array($input)) {
            return array_map(self::toInputFile(...), $input);
        }

        return self::toInputFile($input);
    }

    /** lodash `isEmpty` on a request file input */
    public static function isEmptyFiles(mixed $files): bool
    {
        if ($files === null || $files === []) {
            return true;
        }

        return $files instanceof \ArrayObject && count($files) === 0;
    }

    /** What `JSON.stringify` keeps of a value: no functions, streams or object wrappers. */
    public static function toPlain(mixed $value): mixed
    {
        if ($value instanceof \ArrayObject) {
            $value = $value->getArrayCopy();
        }
        if ($value instanceof \Closure || is_resource($value)) {
            return null;
        }
        if (!is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if ($item instanceof \Closure || is_resource($item) || $item instanceof \Psr\Http\Message\StreamInterface) {
                continue;
            }
            $out[$key] = self::toPlain($item);
        }

        return $out;
    }
}
