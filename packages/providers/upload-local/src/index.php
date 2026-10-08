<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadLocal;

use Strapi\Types\Core\Strapi;
use Strapi\Utils\Errors\PayloadTooLargeError;
use Strapi\Utils\File as FileUtils;

/**
 * Port of packages/providers/upload-local/src/index.ts.
 *
 * `init(providerOptions)` returns the provider instance (the object of methods upstream returns).
 * Upstream reads the ambient `strapi.dirs.static.public`; here the upload plugin passes the
 * instance as second argument. Files are `\ArrayObject`s (or arrays) with `hash`, `ext`, `size`,
 * `name` and a `stream` (a PHP stream resource) or a `buffer` (a binary string); `url` is set on
 * the file once it is written.
 */
final class UploadLocal
{
    public const string UPLOADS_FOLDER_NAME = 'uploads';

    private function __construct(private readonly string $uploadPath, private readonly int|float|null $providerOptionsSizeLimit)
    {
    }

    /** @param array{sizeLimit?: int|float|null} $options */
    public static function init(array $options = [], ?Strapi $strapi = null): self
    {
        $providerOptionsSizeLimit = $options['sizeLimit'] ?? null;

        // TODO V5: remove providerOptions sizeLimit
        if ($providerOptionsSizeLimit) {
            // process.emitWarning
            error_log('[deprecated] In future versions, "sizeLimit" argument will be ignored from upload.config.providerOptions. Move it to upload.config');
        }

        // Ensure uploads folder exists
        $publicDir = $strapi?->dirs()->public ?? (getcwd() . '/public');
        $uploadPath = rtrim($publicDir, '/') . '/' . self::UPLOADS_FOLDER_NAME;
        if (!is_dir($uploadPath)) {
            throw new \RuntimeException("The upload folder ({$uploadPath}) doesn't exist or is not accessible. Please make sure it exists.");
        }

        return new self($uploadPath, $providerOptionsSizeLimit);
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @param array{sizeLimit?: int|float|null}|null $options
     */
    public function checkFileSize(\ArrayAccess|array $file, ?array $options = null): void
    {
        $sizeLimit = $options['sizeLimit'] ?? null;
        $size = is_numeric($file['size'] ?? null) ? (float) $file['size'] : 0.0;
        $name = is_string($file['name'] ?? null) ? $file['name'] : 'undefined';

        // TODO V5: remove providerOptions sizeLimit
        if ($this->providerOptionsSizeLimit) {
            if (FileUtils::kbytesToBytes($size) > $this->providerOptionsSizeLimit) {
                throw new PayloadTooLargeError("{$name} exceeds size limit of " . FileUtils::bytesToHumanReadable($this->providerOptionsSizeLimit) . '.');
            }
        } elseif ($sizeLimit) {
            if (FileUtils::kbytesToBytes($size) > $sizeLimit) {
                throw new PayloadTooLargeError("{$name} exceeds size limit of " . FileUtils::bytesToHumanReadable($sizeLimit) . '.');
            }
        }
    }

    /** @param \ArrayAccess<string, mixed> $file */
    public function uploadStream(\ArrayAccess $file): void
    {
        $stream = $file['stream'] ?? null;
        if (!is_resource($stream)) {
            throw new \RuntimeException('Missing file stream');
        }

        $this->pipe($stream, $this->pathOf($file));

        $file['url'] = $this->urlOf($file);
    }

    /** @param \ArrayAccess<string, mixed> $file */
    public function upload(\ArrayAccess $file): void
    {
        $buffer = $file['buffer'] ?? null;
        if (!is_string($buffer)) {
            throw new \RuntimeException('Missing file buffer');
        }

        // write file in public/assets folder
        $this->write($this->pathOf($file), $buffer);

        $file['url'] = $this->urlOf($file);
    }

    /**
     * @param \ArrayAccess<string, mixed> $newFile
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $oldFile
     */
    public function replaceStream(\ArrayAccess $newFile, \ArrayAccess|array $oldFile): void
    {
        $stream = $newFile['stream'] ?? null;
        if (!is_resource($stream)) {
            throw new \RuntimeException('Missing file stream');
        }

        // If the destination path is unchanged, writing the new file overwrites
        // the old one atomically. If the hash or extension changed, write the
        // new file first then unlink the old one so we never leave a gap.
        $newPath = $this->pathOf($newFile);
        $oldPath = $this->pathOf($oldFile);

        $this->pipe($stream, $newPath);

        $newFile['url'] = $this->urlOf($newFile);

        if ($newPath !== $oldPath && file_exists($oldPath)) {
            $this->unlink($oldPath);
        }
    }

    /**
     * @param \ArrayAccess<string, mixed> $newFile
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $oldFile
     */
    public function replace(\ArrayAccess $newFile, \ArrayAccess|array $oldFile): void
    {
        $buffer = $newFile['buffer'] ?? null;
        if (!is_string($buffer)) {
            throw new \RuntimeException('Missing file buffer');
        }

        $newPath = $this->pathOf($newFile);
        $oldPath = $this->pathOf($oldFile);

        $this->write($newPath, $buffer);

        $newFile['url'] = $this->urlOf($newFile);

        if ($newPath !== $oldPath && file_exists($oldPath)) {
            $this->unlink($oldPath);
        }
    }

    /** @param \ArrayAccess<string, mixed>|array<string, mixed> $file */
    public function delete(\ArrayAccess|array $file): ?string
    {
        $filePath = $this->pathOf($file);

        if (!file_exists($filePath)) {
            return "File doesn't exist";
        }

        // remove file from public/assets folder
        $this->unlink($filePath);

        return null;
    }

    /** @param \ArrayAccess<string, mixed>|array<string, mixed> $file */
    private function pathOf(\ArrayAccess|array $file): string
    {
        return $this->uploadPath . '/' . self::fileName($file);
    }

    /** @param \ArrayAccess<string, mixed>|array<string, mixed> $file */
    private function urlOf(\ArrayAccess|array $file): string
    {
        return '/' . self::UPLOADS_FOLDER_NAME . '/' . self::fileName($file);
    }

    /** @param \ArrayAccess<string, mixed>|array<string, mixed> $file */
    private static function fileName(\ArrayAccess|array $file): string
    {
        $hash = $file['hash'] ?? '';
        $ext = $file['ext'] ?? '';

        // `path.join` normalises the segments: a hash can never leave the uploads folder
        return basename((is_scalar($hash) ? (string) $hash : '') . (is_scalar($ext) ? (string) $ext : ''));
    }

    /** @param resource $stream */
    private function pipe($stream, string $path): void
    {
        $out = @fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException(error_get_last()['message'] ?? "Cannot write {$path}");
        }

        try {
            if (stream_copy_to_stream($stream, $out) === false) {
                throw new \RuntimeException("Cannot write {$path}");
            }
        } finally {
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function write(string $path, string $buffer): void
    {
        if (@file_put_contents($path, $buffer) === false) {
            throw new \RuntimeException(error_get_last()['message'] ?? "Cannot write {$path}");
        }
    }

    private function unlink(string $path): void
    {
        if (!@unlink($path)) {
            throw new \RuntimeException(error_get_last()['message'] ?? "Cannot remove {$path}");
        }
    }
}
