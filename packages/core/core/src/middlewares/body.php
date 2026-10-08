<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Qs;

/**
 * Port of packages/core/core/src/middlewares/body.ts (koa-body): parses JSON, urlencoded, text and
 * multipart bodies into `$ctx->requestBody()`; uploaded files go to `$ctx->files()` (keyed like
 * koa-body's `ctx.request.files`: `files` for the upload plugin). Options keep koa-body's names:
 * `jsonLimit`, `formLimit`, `textLimit` (`'1mb'`-style strings or bytes), `multipart`, `formidable.maxFileSize`.
 *
 * Multipart `data` fields holding JSON are decoded, as the upload endpoints expect.
 */
final class Body
{
    /** @var array<string, mixed> */
    private const DEFAULTS = ['multipart' => true, 'patchKoa' => true, 'jsonLimit' => '1mb', 'formLimit' => '56kb', 'textLimit' => '56kb'];

    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        $bodyConfig = [...self::DEFAULTS, ...$config];
        $jsonLimit = self::bytes($bodyConfig['jsonLimit']);
        $formLimit = self::bytes($bodyConfig['formLimit']);
        $textLimit = self::bytes($bodyConfig['textLimit']);
        $maxFileSize = self::bytes($bodyConfig['formidable']['maxFileSize'] ?? '200mb');
        $multipart = (bool) $bodyConfig['multipart'];

        $gqlEndpoint = null;
        if ($strapi->hasPlugin('graphql')) {
            $gqlEndpoint = $strapi->plugin('graphql')->config('endpoint');
        }

        return static function (Context $ctx, callable $next) use ($jsonLimit, $formLimit, $textLimit, $maxFileSize, $multipart, $gqlEndpoint): void {
            if ($gqlEndpoint !== null && $ctx->url() === $gqlEndpoint) {
                $next();

                return;
            }

            $request = $ctx->request();
            $method = $ctx->method();

            if (in_array($method, ['GET', 'HEAD', 'DELETE'], true) && (int) $request->getHeaderLine('Content-Length') === 0 && !$request->hasHeader('Transfer-Encoding')) {
                $ctx->setRequestBody([]);
                $next();

                return;
            }

            $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));

            if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
                $raw = (string) $request->getBody();
                if (strlen($raw) > $jsonLimit) {
                    $ctx->payloadTooLarge('request entity too large');

                    return;
                }
                if (trim($raw) === '') {
                    $ctx->setRequestBody([]);
                } else {
                    try {
                        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        $ctx->badRequest('Unexpected token in JSON: ' . $e->getMessage());

                        return;
                    }
                    $ctx->setRequestBody($decoded);
                }
            } elseif ($contentType === 'application/x-www-form-urlencoded') {
                $raw = (string) $request->getBody();
                if (strlen($raw) > $formLimit) {
                    $ctx->payloadTooLarge('request entity too large');

                    return;
                }
                $ctx->setRequestBody(Qs::parse($raw));
            } elseif ($contentType === 'multipart/form-data') {
                if (!$multipart) {
                    $ctx->setRequestBody([]);
                    $next();

                    return;
                }
                $parsed = $request->getParsedBody();
                $uploaded = $request->getUploadedFiles();
                $fields = is_array($parsed) ? $parsed : [];
                $files = [];

                if ($uploaded === [] && ($parsed === null || $parsed === [])) {
                    // not pre-parsed by the SAPI (`enable_post_data_reading=Off`, FrankenPHP worker,
                    // tests): parse the raw body. PHP's own parser keeps only the last of repeated
                    // fields (`files` sent several times), koa-body keeps them all.
                    $boundary = null;
                    if (preg_match('/boundary="?([^";]+)"?/i', $request->getHeaderLine('Content-Type'), $m) === 1) {
                        $boundary = $m[1];
                    }
                    if ($boundary !== null) {
                        [$fields, $files] = self::parseMultipart((string) $request->getBody(), $boundary);
                    }
                } else {
                    $files = self::flattenUploads($uploaded);
                }

                foreach (self::allFiles($files) as $file) {
                    if ($file->getSize() !== null && $file->getSize() > $maxFileSize) {
                        $ctx->payloadTooLarge('FileTooBig');

                        return;
                    }
                }

                // koa-body + Strapi: multipart `data` is a JSON string
                foreach ($fields as $key => $value) {
                    if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[') && json_validate($value)) {
                        $fields[$key] = json_decode($value, true);
                    }
                }

                $ctx->setRequestBody($fields);
                $ctx->setFiles($files);
            } elseif (str_starts_with($contentType, 'text/')) {
                $raw = (string) $request->getBody();
                if (strlen($raw) > $textLimit) {
                    $ctx->payloadTooLarge('request entity too large');

                    return;
                }
                $ctx->setRequestBody($raw);
            } else {
                $ctx->setRequestBody([]);
            }

            $next();

            // clean any file that was uploaded
            foreach (self::allFiles($ctx->files()) as $file) {
                try {
                    $stream = $file->getStream();
                    $uri = $stream->getMetadata('uri');
                    $stream->close();
                    if (is_string($uri) && is_file($uri) && str_starts_with($uri, sys_get_temp_dir())) {
                        @unlink($uri);
                    }
                } catch (\Throwable) {
                    // already moved
                }
            }
        };
    }

    /** `'1mb'` → bytes (bytes.js) */
    public static function bytes(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        if (!is_string($value) || preg_match('/^\s*(\d+(?:\.\d+)?)\s*([kmgt]?b)?\s*$/i', $value, $m) !== 1) {
            throw new ValidationError("Invalid size \"{$value}\"");
        }
        $unit = strtolower($m[2] ?? 'b');
        $multiplier = ['b' => 1, 'kb' => 1024, 'mb' => 1024 ** 2, 'gb' => 1024 ** 3, 'tb' => 1024 ** 4][$unit] ?? 1;

        return (int) floor((float) $m[1] * $multiplier);
    }

    /**
     * @param array<string, \Psr\Http\Message\UploadedFileInterface|list<\Psr\Http\Message\UploadedFileInterface>> $files
     * @return list<\Psr\Http\Message\UploadedFileInterface>
     */
    private static function allFiles(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            foreach (is_array($file) ? $file : [$file] as $one) {
                $out[] = $one;
            }
        }

        return $out;
    }

    /**
     * `files[]` (a list) stays a list under `files`, as formidable does with repeated fields.
     *
     * @param array<string, mixed> $uploaded
     * @return array<string, \Psr\Http\Message\UploadedFileInterface|list<\Psr\Http\Message\UploadedFileInterface>>
     */
    private static function flattenUploads(array $uploaded, string $prefix = ''): array
    {
        $out = [];
        foreach ($uploaded as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";
            if ($value instanceof \Psr\Http\Message\UploadedFileInterface) {
                $out[$name] = $value;
            } elseif (is_array($value) && $value !== [] && array_is_list($value) && array_filter($value, static fn (mixed $v): bool => !$v instanceof \Psr\Http\Message\UploadedFileInterface) === []) {
                /** @var list<\Psr\Http\Message\UploadedFileInterface> $value */
                $out[$name] = count($value) === 1 ? $value[0] : $value;
            } elseif (is_array($value)) {
                $out = [...$out, ...self::flattenUploads($value, $name)];
            }
        }

        return $out;
    }

    /**
     * Minimal multipart/form-data parser (RFC 7578) for bodies PHP did not pre-parse.
     *
     * @return array{0: array<string, mixed>, 1: array<string, \Psr\Http\Message\UploadedFileInterface|list<\Psr\Http\Message\UploadedFileInterface>>}
     */
    public static function parseMultipart(string $body, string $boundary): array
    {
        $fields = [];
        $files = [];
        $parts = preg_split('/\r?\n?--' . preg_quote($boundary, '/') . '(?:--)?\r?\n?/', $body) ?: [];

        foreach ($parts as $part) {
            if ($part === '' || $part === "\r\n" || trim($part) === '') {
                continue;
            }
            $split = preg_split("/\r?\n\r?\n/", $part, 2);
            if ($split === false || count($split) < 2) {
                continue;
            }
            [$rawHeaders, $content] = $split;
            $headers = [];
            foreach (preg_split("/\r?\n/", $rawHeaders) ?: [] as $line) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
            }
            $disposition = $headers['content-disposition'] ?? '';
            if (preg_match('/name="([^"]*)"/', $disposition, $m) !== 1) {
                continue;
            }
            $name = $m[1];
            if (preg_match('/filename="([^"]*)"/', $disposition, $fm) === 1) {
                $filename = $fm[1];
                $mime = $headers['content-type'] ?? (self::guessMime($filename) ?? 'application/octet-stream');
                $tmp = tempnam(sys_get_temp_dir(), 'strapi-upload-');
                if ($tmp === false) {
                    continue;
                }
                file_put_contents($tmp, $content);
                $file = new \Nyholm\Psr7\UploadedFile($tmp, strlen($content), UPLOAD_ERR_OK, $filename, $mime);
                // formidable: a repeated file field becomes a list
                $name = str_ends_with($name, '[]') ? substr($name, 0, -2) : $name;
                if (!array_key_exists($name, $files)) {
                    $files[$name] = $file;
                } else {
                    $files[$name] = [...(is_array($files[$name]) ? $files[$name] : [$files[$name]]), $file];
                }
                continue;
            }
            // qs-style nested names (data[title])
            parse_str(rawurlencode($name) . '=' . rawurlencode($content), $decoded);
            $fields = array_merge_recursive($fields, $decoded);
        }

        return [$fields, $files];
    }

    public static function guessMime(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'svg' => 'image/svg+xml', 'pdf' => 'application/pdf', 'json' => 'application/json', 'txt' => 'text/plain',
            'csv' => 'text/csv', 'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'zip' => 'application/zip',
        ][$ext] ?? null;
    }
}
