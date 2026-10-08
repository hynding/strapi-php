<?php

declare(strict_types=1);

namespace Strapi\Upload\Utils;

use Psr\Log\LoggerInterface;
use Strapi\Core\Strapi;

/**
 * Port of server/src/utils/images.ts. A `Blob` is `['data' => string, 'type' => string|null]`.
 */
final class Images
{
    /**
     * Fetches an image from a URL and returns it as a Blob
     *
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @return array{data: string, type: string|null}
     */
    private static function fetchImageAsBlob(Strapi $strapi, \ArrayAccess|array $file, string $serverAbsoluteUrl, LoggerInterface $logger): array
    {
        $filepath = is_string($file['filepath'] ?? null) ? $file['filepath'] : '';
        $fullUrl = ($file['provider'] ?? null) === 'local' ? $serverAbsoluteUrl . $filepath : $filepath;

        $resp = ($strapi->fetch())($fullUrl);
        if (!$resp['ok']) {
            $logger->error('Failed to fetch image', [
                'fullUrl' => $fullUrl,
                'status' => $resp['status'],
            ]);

            throw new \RuntimeException("Failed to fetch image from URL: {$fullUrl} ({$resp['status']})");
        }

        $mimetype = $file['mimetype'] ?? null;

        return ['data' => $resp['body'], 'type' => is_string($mimetype) && $mimetype !== '' ? $mimetype : null];
    }

    /**
     * Fetches every input file as a Blob, in order. Sequential on purpose (same as before).
     *
     * @param list<\ArrayAccess<string, mixed>|array<string, mixed>> $files
     * @return list<array{data: string, type: string|null}>
     */
    public static function fetchImagesAsBlobs(Strapi $strapi, array $files, string $serverAbsoluteUrl, LoggerInterface $logger): array
    {
        $blobs = [];

        foreach ($files as $file) {
            $blobs[] = self::fetchImageAsBlob($strapi, $file, $serverAbsoluteUrl, $logger);
        }

        return $blobs;
    }
}
