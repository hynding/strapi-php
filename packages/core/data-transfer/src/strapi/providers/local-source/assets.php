<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalSource;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Strapi\Queries\Stream as QueryStream;
use Strapi\DataTransfer\Strapi\Utils\UploadProvider;
use Strapi\DataTransfer\Utils\Stream\Bytes;

/**
 * Port of src/strapi/providers/local-source/assets.ts.
 */
final class Assets
{
    /** A missing file: upstream checks `err.code === 'ENOENT'`. */
    public const string ENOENT = 'ENOENT';

    /** @return \Generator<int, string> */
    private static function getFileStream(string $filepath, Strapi $strapi, bool $isLocal = false): \Generator
    {
        if ($isLocal) {
            // Todo: handle errors
            yield from Bytes::readFile($filepath);

            return;
        }

        // fetch the image from remote url and stream it
        $res = ($strapi->fetch())($filepath, ['timeout' => 300]);
        if ($res['status'] !== 200) {
            throw new \RuntimeException("Request failed with status code {$res['status']}");
        }

        foreach (str_split($res['body'], Bytes::CHUNK_SIZE) as $chunk) {
            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    /** @return array{size: int} */
    public static function getFileStatsForTransfer(string $filepath, Strapi $strapi, bool $isLocal = false): array
    {
        if ($isLocal) {
            clearstatcache(true, $filepath);
            if (!file_exists($filepath)) {
                throw new \RuntimeException(self::ENOENT . ": no such file or directory, stat '{$filepath}'", 2);
            }

            return ['size' => (int) filesize($filepath)];
        }

        $res = ($strapi->fetch())($filepath, ['method' => 'GET', 'timeout' => 300]);
        if ($res['status'] !== 200) {
            throw new \RuntimeException("Request failed with status code {$res['status']}");
        }

        $contentLength = null;
        foreach ($res['headers'] as $name => $value) {
            if (strtolower((string) $name) === 'content-length') {
                $contentLength = $value;
            }
        }

        return ['size' => $contentLength !== null && $contentLength !== '' ? (int) $contentLength : strlen($res['body'])];
    }

    public static function isEnoent(\Throwable $err): bool
    {
        return str_starts_with($err->getMessage(), self::ENOENT);
    }

    /** @param array<string, mixed> $file */
    public static function signUploadFileForTransfer(Strapi $strapi, array &$file): void
    {
        $providerName = UploadProvider::configuredName($strapi);
        $isPrivate = UploadProvider::isPrivate($strapi);
        if (($file['provider'] ?? null) === $providerName && $isPrivate) {
            $signUrl = static function (array &$f) use ($strapi): void {
                $f['url'] = UploadProvider::signedUrl($strapi, $f) ?? $f['url'] ?? null;
            };

            $signUrl($file);
            if (is_array($file['formats'] ?? null)) {
                foreach (array_keys($file['formats']) as $format) {
                    if (is_array($file['formats'][$format])) {
                        $signUrl($file['formats'][$format]);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $file */
    private static function missingAssetWarningMessage(array $file, string $filepath, ?string $format = null): string
    {
        $formatPart = $format !== null ? " (format: {$format})" : '';

        return "[Data transfer] Media item {$file['id']} (hash: {$file['hash']}) exists in database but no corresponding file was found to transfer{$formatPart}. Path: {$filepath}";
    }

    /** node `path.join(public, url)` */
    public static function publicPath(Strapi $strapi, string $url): string
    {
        return rtrim($strapi->dirs()->public, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Generate and consume assets streams in order to stream each file individually
     *
     * @param array{onWarning?: callable(string): void} $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function createAssetsStream(Strapi $strapi, array $options = []): \Generator
    {
        $warnMissingAsset = static function (string $message) use ($strapi, $options): void {
            $strapi->log()->warning($message);
            if (isset($options['onWarning'])) {
                ($options['onWarning'])($message);
            }
        };

        foreach (QueryStream::rows($strapi, 'plugin::upload.file') as $file) {
            $isLocalProvider = ($file['provider'] ?? null) === 'local';
            if (!$isLocalProvider) {
                self::signUploadFileForTransfer($strapi, $file);
            }
            $filepath = $isLocalProvider ? self::publicPath($strapi, (string) $file['url']) : (string) $file['url'];

            try {
                $stats = self::getFileStatsForTransfer($filepath, $strapi, $isLocalProvider);
            } catch (\RuntimeException $err) {
                if (self::isEnoent($err)) {
                    $warnMissingAsset(self::missingAssetWarningMessage($file, $filepath));
                    continue;
                }
                throw $err;
            }
            $stream = self::getFileStream($filepath, $strapi, $isLocalProvider);

            yield [
                'metadata' => $file,
                'filepath' => $filepath,
                'filename' => $file['hash'] . ($file['ext'] ?? ''),
                'stream' => $stream,
                'stats' => ['size' => $stats['size']],
            ];

            if (is_array($file['formats'] ?? null)) {
                foreach ($file['formats'] as $format => $fileFormat) {
                    if (!is_array($fileFormat)) {
                        continue;
                    }
                    $fileFormatFilepath = $isLocalProvider ? self::publicPath($strapi, (string) $fileFormat['url']) : (string) $fileFormat['url'];

                    try {
                        $fileFormatStats = self::getFileStatsForTransfer($fileFormatFilepath, $strapi, $isLocalProvider);
                    } catch (\RuntimeException $err) {
                        if (self::isEnoent($err)) {
                            $warnMissingAsset(self::missingAssetWarningMessage($file, $fileFormatFilepath, (string) $format));
                            continue;
                        }
                        throw $err;
                    }
                    $fileFormatStream = self::getFileStream($fileFormatFilepath, $strapi, $isLocalProvider);
                    $metadata = [...$fileFormat, 'type' => (string) $format, 'id' => $file['id'], 'mainHash' => $file['hash']];

                    yield [
                        'metadata' => $metadata,
                        'filepath' => $fileFormatFilepath,
                        'filename' => $fileFormat['hash'] . ($fileFormat['ext'] ?? ''),
                        'stream' => $fileFormatStream,
                        'stats' => ['size' => $fileFormatStats['size']],
                    ];
                }
            }
        }
    }
}
