<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Upload\Provider as UploadProvider;
use Strapi\Utils\File as FileUtils;

/**
 * Port of server/src/services/provider.ts. `file.stream` is a stream resource opened with the
 * file's `getStream()`, `file.buffer` a binary string.
 */
final class Provider
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function provider(): UploadProvider
    {
        $provider = $this->strapi->plugin('upload')->provider;
        if (!$provider instanceof UploadProvider) {
            throw new \RuntimeException('The upload provider is not initialized');
        }

        return $provider;
    }

    /** @param \ArrayObject<string, mixed> $file */
    public function checkFileSize(\ArrayObject $file): void
    {
        $sizeLimit = $this->strapi->config()->get('plugin::upload.sizeLimit');
        $this->provider()->checkFileSize($file, ['sizeLimit' => is_numeric($sizeLimit) ? $sizeLimit + 0 : null]);
    }

    /** @param \ArrayObject<string, mixed> $file */
    private static function open(\ArrayObject $file): mixed
    {
        $getStream = $file['getStream'] ?? null;
        if (!is_callable($getStream)) {
            throw new \RuntimeException('Missing file stream');
        }

        return $getStream();
    }

    /** @param \ArrayObject<string, mixed> $file */
    private static function closeStream(\ArrayObject $file): void
    {
        $stream = $file['stream'] ?? null;
        if (is_resource($stream)) {
            fclose($stream);
        }
        unset($file['stream']);
    }

    /** @param \ArrayObject<string, mixed> $file */
    public function upload(\ArrayObject $file): void
    {
        $provider = $this->provider();

        if ($provider->has('uploadStream')) {
            $file['stream'] = self::open($file);
            try {
                $provider->uploadStream($file);
            } finally {
                self::closeStream($file);
            }

            unset($file['filepath']);
        } else {
            $file['buffer'] = FileUtils::streamToBuffer(self::open($file));
            $provider->upload($file);

            unset($file['buffer'], $file['filepath']);
        }
    }

    /**
     * @param \ArrayObject<string, mixed> $newFile
     * @param array<string, mixed>|\ArrayObject<string, mixed> $oldFile
     */
    public function replace(\ArrayObject $newFile, array|\ArrayObject $oldFile): void
    {
        $provider = $this->provider();

        if ($provider->has('replaceStream')) {
            $newFile['stream'] = self::open($newFile);
            try {
                $provider->replaceStream($newFile, $oldFile);
            } finally {
                self::closeStream($newFile);
            }

            unset($newFile['filepath']);

            return;
        }

        if ($provider->has('replace')) {
            $newFile['buffer'] = FileUtils::streamToBuffer(self::open($newFile));
            $provider->replace($newFile, $oldFile);

            unset($newFile['buffer'], $newFile['filepath']);

            return;
        }

        // Fallback: delete old then upload new — preserves current behavior for the file.
        $provider->delete(is_array($oldFile) ? new \ArrayObject($oldFile) : $oldFile);
        $this->upload($newFile);
    }
}
