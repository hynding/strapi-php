<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants;
use Strapi\Upload\Provider as UploadProvider;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\File as FileUtils;

/**
 * Port of server/src/services/file.ts.
 *
 * `fetchUrlToInputFile` streams the response to disk from `strapi.fetch()->open()`, the streaming
 * twin of `strapi.fetch` (same proxy settings, same interception).
 *
 * @phpstan-type UrlFetchedFile array{filepath: string, originalFilename: string, mimetype: string, size: int, tmpWorkingDirectory?: string}
 * @phpstan-type UrlFetchProgress array{bytesWritten: int, totalBytes: int|null}
 */
final class File
{
    public const int FETCH_TIMEOUT_MS = 60_000; // 60 seconds

    /**
     * Blocks loopback, link-local (cloud metadata), and RFC-1918 private ranges to prevent SSRF
     *
     * @var list<array{0: string, 1: int}>
     */
    private const array SSRF_BLOCK_LIST = [
        ['127.0.0.0', 8], // loopback
        ['10.0.0.0', 8], // RFC-1918
        ['172.16.0.0', 12], // RFC-1918
        ['192.168.0.0', 16], // RFC-1918
        ['169.254.0.0', 16], // link-local / cloud metadata (AWS, GCP, Azure)
        ['::1', 128], // IPv6 loopback
        ['fc00::', 7], // IPv6 unique local
        ['fe80::', 10], // IPv6 link-local
    ];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** `net.BlockList#check(address, type)` over {@see self::SSRF_BLOCK_LIST} */
    public static function isBlockedAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        foreach (self::SSRF_BLOCK_LIST as [$subnet, $prefix]) {
            $net = inet_pton($subnet);
            if ($net === false || strlen($net) !== strlen($packed)) {
                continue;
            }
            $bytes = intdiv($prefix, 8);
            $bits = $prefix % 8;
            if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            if ($bits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $bits)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts filename from a URL path or Content-Disposition header
     */
    public static function getFilenameFromUrl(string $url, ?string $contentDisposition = null): string
    {
        // Try Content-Disposition header first
        if ($contentDisposition !== null && $contentDisposition !== '') {
            // Extracts filename from Content-Disposition header (e.g. filename="photo.jpg" or filename*=UTF-8''photo.jpg)
            if (preg_match('/filename\*?=[\'"]?(?:UTF-\d[\'"]*)?([^;\r\n"\']*)[\'"]?/i', $contentDisposition, $match) === 1 && $match[1] !== '') {
                // Use path.basename to prevent path traversal attacks
                return basename(rawurldecode($match[1]));
            }
        }

        // Fall back to URL path
        $parts = parse_url($url);
        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $pathname = $parts['path'] ?? '/';
            $segments = explode('/', $pathname);
            $filename = end($segments);
            if ($filename !== false && $filename !== '') {
                // Use path.basename to prevent path traversal attacks (e.g., URL-encoded separators)
                return basename(rawurldecode($filename));
            }
        }

        // Generate a timestamp-based default filename
        $now = new \DateTimeImmutable();
        $date = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'); // 2024-02-23
        $time = $now->format('His'); // 143052

        return "untitled_{$date}_{$time}";
    }

    /**
     * Fetches a URL and streams it to a temporary file
     * Returns an InputFile-compatible object for use with the upload pipeline
     *
     * The response body is never buffered in memory: it is piped straight to disk, so heap usage
     * stays bounded regardless of the remote file size. `sizeLimit` is enforced on the bytes actually
     * received, not only on the Content-Length header (which may be absent or wrong).
     *
     * `onProgress` (optional) reports raw progress: once with `bytesWritten: 0` before any byte is
     * read, then once per chunk. A throwing callback is logged and ignored.
     *
     * @param (callable(UrlFetchProgress): void)|null $onProgress
     * @return array{file: \ArrayObject<string, mixed>}
     */
    public function fetchUrlToInputFile(string $url, string $tmpWorkingDirectory, int|float|null $sizeLimit = null, ?callable $onProgress = null): array
    {
        // Validate URL protocol
        $parsedUrl = parse_url($url);
        if (!is_array($parsedUrl) || !isset($parsedUrl['scheme']) || (!isset($parsedUrl['host']) && in_array(strtolower($parsedUrl['scheme']), ['http', 'https'], true))) {
            throw new ApplicationError("Invalid URL: {$url}");
        }

        if (!in_array(strtolower($parsedUrl['scheme']), ['http', 'https'], true)) {
            throw new ApplicationError("Invalid URL protocol. Only http and https are allowed: {$url}");
        }

        $hostname = trim((string) ($parsedUrl['host'] ?? ''), '[]');

        // Resolve hostname and block private/internal IP ranges to prevent SSRF
        $address = filter_var($hostname, FILTER_VALIDATE_IP) !== false ? $hostname : self::lookup($hostname);
        if ($address === null) {
            throw new ApplicationError("Could not resolve hostname: {$hostname}");
        }
        if (self::isBlockedAddress($address)) {
            throw new ApplicationError("URL resolves to a blocked address: {$url}");
        }

        // use strapi.fetch so we can intercept requests and support proxy settings
        $handle = $this->strapi->fetch()->open($url, ['timeout' => self::FETCH_TIMEOUT_MS / 1000]);
        $headers = $handle['headers'];
        $status = $handle['status'];
        $stream = $handle['stream'];

        try {
            if ($status < 200 || $status >= 300) {
                throw new ApplicationError("Failed to fetch URL: {$url} ({$status} {$handle['statusText']})");
            }

            $tooLargeError = static fn (): ApplicationError => new ApplicationError(
                'File too large: maximum allowed size is ' . FileUtils::bytesToHumanReadable($sizeLimit ?? 0),
            );

            $contentLength = $headers['content-length'] ?? '';
            $totalBytes = preg_match('/^\s*(\d+)/', $contentLength, $m) === 1 ? (int) $m[1] : null;

            // Check Content-Length header for early rejection of large files
            if ($sizeLimit && $totalBytes !== null && $totalBytes > $sizeLimit) {
                throw $tooLargeError();
            }

            $reportProgress = function (int $bytesWritten, ?int $totalBytes) use ($onProgress): void {
                if ($onProgress === null) {
                    return;
                }

                try {
                    $onProgress(['bytesWritten' => $bytesWritten, 'totalBytes' => $totalBytes]);
                } catch (\Throwable $error) {
                    $this->strapi->log()->warning('URL fetch progress callback threw, ignoring: ' . $error->getMessage());
                }
            };

            // Announce the total before any bytes are dispatched.
            $reportProgress(0, $totalBytes);

            // Get content type and filename
            $contentType = trim(explode(';', $headers['content-type'] ?? '')[0]);
            $contentType = $contentType !== '' ? $contentType : 'application/octet-stream';
            $filename = self::getFilenameFromUrl($handle['url'], $headers['content-disposition'] ?? null);

            $tmpFilePath = $tmpWorkingDirectory . '/' . $filename;

            // Stream the response body to the temp file, counting bytes as they go so the size limit is
            // enforced even when Content-Length is missing or lying.
            $bytesWritten = 0;

            try {
                $out = @fopen($tmpFilePath, 'wb');
                if ($out === false) {
                    throw new \RuntimeException("Cannot write {$tmpFilePath}");
                }
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false) {
                            throw new ApplicationError('socket hang up');
                        }
                        if ($chunk === '') {
                            // a buffered body (an intercepted `strapi.fetch`) has no `timed_out`
                            $meta = stream_get_meta_data($stream);
                            if (!empty($meta['timed_out'])) {
                                throw new ApplicationError("Request timed out while fetching URL: {$url}");
                            }
                            continue;
                        }

                        $bytesWritten += strlen($chunk);

                        if ($sizeLimit && $bytesWritten > $sizeLimit) {
                            throw $tooLargeError();
                        }

                        $reportProgress($bytesWritten, $totalBytes);
                        fwrite($out, $chunk);
                    }
                } finally {
                    fclose($out);
                }
            } catch (\Throwable $error) {
                // Remove the partially written file — the caller only cleans up the temp directory once all
                // URLs have been processed.
                if (file_exists($tmpFilePath) && !@unlink($tmpFilePath)) {
                    $this->strapi->log()->warning("Could not remove partial temp file {$tmpFilePath}");
                }

                throw $error;
            }
        } finally {
            fclose($stream);
        }

        // Derive the size from the file on disk rather than from a buffer we never hold
        clearstatcache(true, $tmpFilePath);
        $size = (int) filesize($tmpFilePath);

        // Create file object compatible with upload pipeline
        $fetchedFile = new \ArrayObject([
            'filepath' => $tmpFilePath,
            'originalFilename' => $filename,
            'mimetype' => $contentType,
            'size' => $size,
            'tmpWorkingDirectory' => $tmpWorkingDirectory,
        ]);

        return ['file' => $fetchedFile];
    }

    /** `dns.lookup(hostname)`: the first address, or null */
    private static function lookup(string $hostname): ?string
    {
        if ($hostname === '') {
            return null;
        }
        if (strtolower($hostname) === 'localhost') {
            return '127.0.0.1';
        }

        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    return $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    return $record['ipv6'];
                }
            }
        }

        $ip = gethostbyname($hostname);

        return $ip !== $hostname ? $ip : null;
    }

    public function getFolderPath(?int $folderId = null): string
    {
        if (!$folderId) {
            return '/';
        }

        $parentFolder = $this->strapi->db()->query(Constants::FOLDER_MODEL_UID)->findOne(['where' => ['id' => $folderId]]);

        if (!is_array($parentFolder)) {
            // upstream reads `parentFolder.path` and throws a TypeError
            throw new \TypeError("Cannot read properties of null (reading 'path')");
        }

        return (string) $parentFolder['path'];
    }

    /**
     * @param list<int|string> $ids
     * @return list<array<string, mixed>>
     */
    public function deleteByIds(array $ids = []): array
    {
        $filesToDelete = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->findMany(['where' => ['id' => ['$in' => $ids]]]);

        foreach ($filesToDelete as $file) {
            Utils::getService('upload', $this->strapi)->remove($file);
        }

        return array_values($filesToDelete);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function signFileUrls(array $file): array
    {
        $provider = $this->strapi->plugin('upload')->provider;
        $providerConfig = $this->strapi->config()->get('plugin::upload.provider');
        $isPrivate = $provider instanceof UploadProvider && $provider->isPrivate();
        $file['isUrlSigned'] = false;

        // Check file provider and if provider is private
        if (($file['provider'] ?? null) !== $providerConfig || !$isPrivate) {
            return $file;
        }

        $signUrl = static function (array $file) use ($provider): array {
            $signedUrl = $provider->getSignedUrl($file);
            $file['url'] = $signedUrl['url'] ?? null;
            $file['isUrlSigned'] = true;

            return $file;
        };

        // Sign each file format
        $signedFile = $signUrl($file);
        if (!empty($file['formats']) && is_array($signedFile['formats'] ?? null)) {
            foreach ($signedFile['formats'] as $key => $format) {
                if (is_array($format)) {
                    $signedFile['formats'][$key] = $signUrl($format);
                }
            }
        }

        return $signedFile;
    }
}
