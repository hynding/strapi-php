<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalSource;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Strapi\Queries\Stream as QueryStream;

/**
 * Port of src/strapi/providers/local-source/estimate-asset-totals.ts.
 */
final class EstimateAssetTotals
{
    /** Strapi stores byte size on each file record; use for remote totals to avoid per-URL HTTP. */
    private static function hasReliableDbSize(mixed $size): bool
    {
        return (is_int($size) || is_float($size)) && is_finite((float) $size) && $size >= 0;
    }

    /**
     * When every main + format has a DB size, remote rows need no signing or HTTP stat.
     *
     * @param array<string, mixed> $file
     */
    private static function remoteRowCanUseDbOnly(array $file): bool
    {
        if (!self::hasReliableDbSize($file['size'] ?? null)) {
            return false;
        }
        if (empty($file['formats']) || !is_array($file['formats'])) {
            return true;
        }
        foreach ($file['formats'] as $format) {
            if (!is_array($format) || !self::hasReliableDbSize($format['size'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sum sizes and counts for the same asset rows `createAssetsStream` would yield (main + formats),
     * skipping missing files with ENOENT like the stream does. Used for transfer progress totals / ETA.
     *
     * - **Local (`provider === 'local'`):** `stat` on disk (source of truth; matches ENOENT skips).
     * - **Remote:** sum `size` from DB when present on main and every format; otherwise sign + `fetch` / `Content-Length` like before.
     *
     * @return array{totalBytes: int, totalCount: int}
     */
    public static function estimateAssetTotals(Strapi $strapi): array
    {
        $totalBytes = 0;
        $totalCount = 0;

        foreach (QueryStream::rows($strapi, 'plugin::upload.file') as $file) {
            $isLocalProvider = ($file['provider'] ?? null) === 'local';

            if ($isLocalProvider) {
                $filepath = Assets::publicPath($strapi, (string) $file['url']);
                try {
                    $stats = Assets::getFileStatsForTransfer($filepath, $strapi, true);
                    $totalBytes += $stats['size'];
                    ++$totalCount;
                } catch (\RuntimeException $err) {
                    if (Assets::isEnoent($err)) {
                        $strapi->log()->warning("[Data transfer] Skipping missing asset file: {$filepath}");
                        continue;
                    }
                    throw $err;
                }

                if (is_array($file['formats'] ?? null)) {
                    foreach ($file['formats'] as $fileFormat) {
                        $fileFormatFilepath = Assets::publicPath($strapi, (string) ($fileFormat['url'] ?? ''));
                        try {
                            $fileFormatStats = Assets::getFileStatsForTransfer($fileFormatFilepath, $strapi, true);
                            $totalBytes += $fileFormatStats['size'];
                            ++$totalCount;
                        } catch (\RuntimeException $err) {
                            if (Assets::isEnoent($err)) {
                                $strapi->log()->warning("[Data transfer] Skipping missing asset file: {$fileFormatFilepath}");
                                continue;
                            }
                            throw $err;
                        }
                    }
                }

                continue;
            }

            // Remote: prefer DB sizes (fast); fall back to signed URL + HTTP where `size` is missing.
            if (self::remoteRowCanUseDbOnly($file)) {
                $totalBytes += (int) $file['size'];
                ++$totalCount;
                if (is_array($file['formats'] ?? null)) {
                    foreach ($file['formats'] as $format) {
                        $totalBytes += (int) $format['size'];
                        ++$totalCount;
                    }
                }
                continue;
            }

            Assets::signUploadFileForTransfer($strapi, $file);

            if (self::hasReliableDbSize($file['size'] ?? null)) {
                $totalBytes += (int) $file['size'];
                ++$totalCount;
            } else {
                try {
                    $stats = Assets::getFileStatsForTransfer((string) $file['url'], $strapi, false);
                    $totalBytes += $stats['size'];
                    ++$totalCount;
                } catch (\RuntimeException $err) {
                    if (Assets::isEnoent($err)) {
                        $strapi->log()->warning("[Data transfer] Skipping missing asset file: {$file['url']}");
                        continue;
                    }
                    throw $err;
                }
            }

            if (is_array($file['formats'] ?? null)) {
                foreach ($file['formats'] as $fileFormat) {
                    $fileFormatFilepath = (string) ($fileFormat['url'] ?? '');

                    if (self::hasReliableDbSize($fileFormat['size'] ?? null)) {
                        $totalBytes += (int) $fileFormat['size'];
                        ++$totalCount;
                    } else {
                        try {
                            $fileFormatStats = Assets::getFileStatsForTransfer($fileFormatFilepath, $strapi, false);
                            $totalBytes += $fileFormatStats['size'];
                            ++$totalCount;
                        } catch (\RuntimeException $err) {
                            if (Assets::isEnoent($err)) {
                                $strapi->log()->warning("[Data transfer] Skipping missing asset file: {$fileFormatFilepath}");
                                continue;
                            }
                            throw $err;
                        }
                    }
                }
            }
        }

        return ['totalBytes' => $totalBytes, 'totalCount' => $totalCount];
    }
}
