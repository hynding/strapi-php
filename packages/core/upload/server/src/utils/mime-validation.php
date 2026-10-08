<?php

declare(strict_types=1);

namespace Strapi\Upload\Utils;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/utils/mime-validation.ts.
 *
 * Files are the `\ArrayObject` formidable-like objects of {@see Utils::toInputFile()} (or any
 * array / `\ArrayObject` with `filepath`, `originalFilename`, `mimetype`…). `file-type` and
 * `mime-types` are {@see FileType} and {@see MimeTypes}.
 *
 * @phpstan-type SecurityConfig array{allowedTypes?: mixed, deniedTypes?: mixed}
 * @phpstan-type UploadValidationError array{code: 'MIME_TYPE_NOT_ALLOWED'|'VALIDATION_ERROR'|'UNKNOWN_ERROR', message: string, details: array<string, mixed>}
 * @phpstan-type ValidationResult array{isValid: bool, error?: UploadValidationError, detectedMime?: string}
 * @phpstan-type ErrorDetail array{file: mixed, originalIndex: int, error: UploadValidationError}
 * @phpstan-type FileUploadError array{name: string, message: string}
 * @phpstan-type PrepareUploadResult array{validFiles: list<\ArrayObject<string, mixed>>, filteredBody: mixed, errors: list<FileUploadError>}
 */
final class MimeValidation
{
    private static function readFileChunk(string $filePath, int $chunkSize = 4100): string
    {
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            $error = error_get_last();

            throw new \RuntimeException($error['message'] ?? "ENOENT: no such file or directory, open '{$filePath}'");
        }

        try {
            $buffer = fread($handle, max(1, $chunkSize));

            return $buffer === false ? '' : $buffer;
        } finally {
            fclose($handle);
        }
    }

    /** @param \ArrayAccess<string, mixed>|array<string, mixed> $file */
    public static function detectMimeType(\ArrayAccess|array $file): ?string
    {
        // Support multiple property names used by different multipart parsers (formidable uses filepath)
        $filePath = $file['filepath'] ?? $file['path'] ?? $file['tempFilePath'] ?? $file['filePath'] ?? null;

        if (is_string($filePath) && $filePath !== '') {
            try {
                $buffer = self::readFileChunk($filePath, 4100);
            } catch (\Throwable $error) {
                throw new \RuntimeException('Failed to read file: ' . $error->getMessage(), 0, $error);
            }
        } elseif (is_string($file['buffer'] ?? null)) {
            $buffer = substr($file['buffer'], 0, 4100);
        } else {
            // No file path or buffer; cannot detect MIME. Validation will use declared/extension or reject.
            return null;
        }

        return FileType::fromBuffer($buffer)['mime'] ?? null;
    }

    /** @param list<string>|null $patterns */
    private static function matchesMimePattern(string $mimeType, ?array $patterns): bool
    {
        if ($patterns === null || $patterns === []) {
            return false;
        }

        foreach ($patterns as $pattern) {
            $normalizedPattern = strtolower($pattern);
            $normalizedMimeType = strtolower($mimeType);

            if (str_contains($normalizedPattern, '*')) {
                // `new RegExp(pattern.replace(/\*/g, '.*'))`: the other characters keep their regex meaning
                $regexPattern = str_replace('*', '.*', $normalizedPattern);
                if (@preg_match('/^' . str_replace('/', '\/', $regexPattern) . '$/', $normalizedMimeType) === 1) {
                    return true;
                }
                continue;
            }

            if ($normalizedPattern === $normalizedMimeType) {
                return true;
            }
        }

        return false;
    }

    /** @param SecurityConfig $config */
    public static function isMimeTypeAllowed(string $mimeType, array $config): bool
    {
        $allowedTypes = self::list($config['allowedTypes'] ?? null);
        $deniedTypes = self::list($config['deniedTypes'] ?? null);

        if ($mimeType === '') {
            return false;
        }

        if ($deniedTypes !== null && $deniedTypes !== [] && self::matchesMimePattern($mimeType, $deniedTypes)) {
            return false;
        }

        if ($allowedTypes !== null) {
            return $allowedTypes !== [] && self::matchesMimePattern($mimeType, $allowedTypes);
        }

        return true;
    }

    /** @return list<string>|null */
    private static function list(mixed $value): ?array
    {
        return is_array($value) ? array_values(array_map(strval(...), $value)) : null;
    }

    /**
     * Single gate for allow/deny. Returns ValidationResult.
     *
     * @param SecurityConfig $config
     * @return ValidationResult
     */
    private static function validateAllowBanLists(string $mimetype, string $fileName, string $declaredType, ?string $detectedType, array $config): array
    {
        $allowedTypes = self::list($config['allowedTypes'] ?? null);
        $deniedTypes = self::list($config['deniedTypes'] ?? null);

        if ($mimetype === '') {
            return [
                'isValid' => false,
                'error' => [
                    'code' => 'MIME_TYPE_NOT_ALLOWED',
                    'message' => 'MIME type is not allowed',
                    'details' => self::omitNull([
                        'fileName' => $fileName,
                        'reason' => 'No MIME type to validate',
                        'declaredType' => $declaredType,
                        'detectedType' => $detectedType,
                        'allowedTypes' => $config['allowedTypes'] ?? null,
                        'deniedTypes' => $config['deniedTypes'] ?? null,
                    ]),
                ],
            ];
        }
        if ($deniedTypes !== null && $deniedTypes !== [] && self::matchesMimePattern($mimetype, $deniedTypes)) {
            return self::buildNotAllowedError($fileName, $declaredType, $detectedType, $mimetype, $config);
        }
        if ($allowedTypes === null) {
            return ['isValid' => true, 'detectedMime' => $mimetype];
        }
        if ($allowedTypes === []) {
            return [
                'isValid' => false,
                'error' => [
                    'code' => 'MIME_TYPE_NOT_ALLOWED',
                    'message' => 'MIME type is not allowed',
                    'details' => self::omitNull([
                        'fileName' => $fileName,
                        'reason' => 'Allow list is empty',
                        'declaredType' => $declaredType,
                        'detectedType' => $detectedType,
                        'allowedTypes' => $config['allowedTypes'] ?? null,
                        'deniedTypes' => $config['deniedTypes'] ?? null,
                    ]),
                ],
            ];
        }
        if (self::matchesMimePattern($mimetype, $allowedTypes)) {
            return ['isValid' => true, 'detectedMime' => $mimetype];
        }

        return [
            'isValid' => false,
            'error' => [
                'code' => 'MIME_TYPE_NOT_ALLOWED',
                'message' => "File type '{$mimetype}' is not allowed",
                'details' => self::omitNull([
                    'fileName' => $fileName,
                    'declaredType' => $declaredType,
                    'detectedType' => $detectedType,
                    'finalType' => $mimetype,
                    'allowedTypes' => $config['allowedTypes'] ?? null,
                    'deniedTypes' => $config['deniedTypes'] ?? null,
                ]),
            ],
        ];
    }

    private static function isDeclaredGeneric(string $declaredMimeType): bool
    {
        return $declaredMimeType === '' || $declaredMimeType === 'application/octet-stream';
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @return array{fileName: string, declaredMimeType: string}
     */
    public static function extractFileInfo(\ArrayAccess|array $file): array
    {
        $fileName = self::firstTruthy($file, ['originalFilename', 'name', 'filename', 'newFilename', 'originalname']) ?? 'unknown';
        $declaredMimeType = self::firstTruthy($file, ['mimetype', 'type', 'mimeType', 'mime']) ?? '';

        return ['fileName' => $fileName, 'declaredMimeType' => $declaredMimeType];
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @param list<string> $keys
     */
    private static function firstTruthy(\ArrayAccess|array $file, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $file[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @param SecurityConfig $config
     * @return ValidationResult
     */
    public static function validateFile(\ArrayAccess|array $file, array $config, Strapi $strapi): array
    {
        $allowedTypes = self::list($config['allowedTypes'] ?? null);
        $deniedTypes = self::list($config['deniedTypes'] ?? null);

        ['fileName' => $fileName, 'declaredMimeType' => $declaredMimeType] = self::extractFileInfo($file);

        $fileExt = strtolower(MimeTypes::extname($fileName));
        $lookedUp = $fileExt !== '' ? MimeTypes::lookup($fileExt) : false;
        $expectedMimeFromExt = $lookedUp === false ? null : $lookedUp;

        // Reject if declared type is denied.
        if (!self::isDeclaredGeneric($declaredMimeType) && $deniedTypes !== null && $deniedTypes !== [] && self::matchesMimePattern($declaredMimeType, $deniedTypes)) {
            return self::buildNotAllowedError($fileName, $declaredMimeType, null, $declaredMimeType, $config);
        }

        // Reject if extension's type is denied.
        if ($expectedMimeFromExt !== null && $deniedTypes !== null && $deniedTypes !== [] && self::matchesMimePattern($expectedMimeFromExt, $deniedTypes)) {
            return self::buildNotAllowedError($fileName, $declaredMimeType, null, $expectedMimeFromExt, $config);
        }

        // Run content detection.
        $detectedMime = null;
        try {
            $detectedMime = self::detectMimeType($file);
        } catch (\Throwable $error) {
            $errMsg = $error->getMessage();
            $strapi->log()->warning("Failed to detect MIME type from file: {$errMsg}", [
                'fileName' => $fileName,
                'error' => $errMsg,
            ]);
        }

        $declaredMatchesExtension = !self::isDeclaredGeneric($declaredMimeType)
            && $expectedMimeFromExt !== null
            && (self::matchesMimePattern($declaredMimeType, [$expectedMimeFromExt]) || self::matchesMimePattern($expectedMimeFromExt, [$declaredMimeType]));
        $detectedMatchesDeclared = $detectedMime !== null && $declaredMimeType !== '' && self::matchesMimePattern($detectedMime, [$declaredMimeType]);

        // Trusted declaration: declared matches extension and detection confirms.
        if ($declaredMatchesExtension && $detectedMatchesDeclared) {
            return self::validateAllowBanLists($declaredMimeType, $fileName, $declaredMimeType, $detectedMime, $config);
        }

        // Reject if detected type is denied.
        if ($detectedMime !== null && $deniedTypes !== null && $deniedTypes !== [] && self::matchesMimePattern($detectedMime, $deniedTypes)) {
            return self::buildNotAllowedError($fileName, $declaredMimeType, $detectedMime, $detectedMime, $config);
        }

        // Reject if detected is not in allow list (extension/declared cannot override).
        // Exception: file-type often returns application/zip for Office formats (docx, xlsx); skip so the next block can allow via extension type.
        $isZipWithAllowedExt = $detectedMime === 'application/zip'
            && $expectedMimeFromExt !== null
            && $expectedMimeFromExt !== 'application/zip'
            && $allowedTypes !== null
            && $allowedTypes !== []
            && self::matchesMimePattern($expectedMimeFromExt, $allowedTypes);
        // Exception: file-type often returns application/xml for SVG; trust .svg extension when image/svg+xml is allowed.
        $isSvgXmlWithAllowedExt = $detectedMime === 'application/xml'
            && $fileExt === '.svg'
            && $expectedMimeFromExt === 'image/svg+xml'
            && $allowedTypes !== null
            && $allowedTypes !== []
            && self::matchesMimePattern($expectedMimeFromExt, $allowedTypes);
        if (
            $detectedMime !== null
            && $allowedTypes !== null
            && $allowedTypes !== []
            && !self::matchesMimePattern($detectedMime, $allowedTypes)
            && !$isZipWithAllowedExt
            && !$isSvgXmlWithAllowedExt
        ) {
            return [
                'isValid' => false,
                'error' => [
                    'code' => 'MIME_TYPE_NOT_ALLOWED',
                    'message' => 'MIME type is not allowed',
                    'details' => self::omitNull([
                        'fileName' => $fileName,
                        'reason' => 'File content was detected as a type not in the allow list; extension or declared type cannot override',
                        'declaredType' => $declaredMimeType,
                        'detectedType' => $detectedMime,
                        'expectedMimeFromExtension' => $expectedMimeFromExt,
                        'allowedTypes' => $config['allowedTypes'] ?? null,
                        'deniedTypes' => $config['deniedTypes'] ?? null,
                    ]),
                ],
            ];
        }

        // Use detected type when defined and (no extension, or detected matches extension, or declared is generic).
        $detectedMatchesExtension = $detectedMime !== null && $expectedMimeFromExt !== null && self::matchesMimePattern($detectedMime, [$expectedMimeFromExt]);
        if ($detectedMime !== null && ($expectedMimeFromExt === null || $detectedMatchesExtension || self::isDeclaredGeneric($declaredMimeType))) {
            // Office/zip exception: use extension type when detection returned application/zip and extension is in allow list.
            if ($detectedMime === 'application/zip' && $expectedMimeFromExt !== null && $expectedMimeFromExt !== 'application/zip') {
                $extResult = self::validateAllowBanLists($expectedMimeFromExt, $fileName, $declaredMimeType, $detectedMime, $config);
                if ($extResult['isValid']) {
                    $strapi->log()->warning('MIME type detection returned application/zip; trusting extension for allow list', [
                        'fileName' => $fileName,
                        'fileExtension' => $fileExt,
                        'expectedMimeFromExtension' => $expectedMimeFromExt,
                    ]);

                    return $extResult;
                }
            }

            return self::validateAllowBanLists($detectedMime, $fileName, $declaredMimeType, $detectedMime, $config);
        }

        // Use extension's type when present.
        if ($expectedMimeFromExt !== null) {
            return self::validateAllowBanLists($expectedMimeFromExt, $fileName, $declaredMimeType, $detectedMime, $config);
        }

        // Use declared type as last resort.
        if ($declaredMimeType !== '') {
            return self::validateAllowBanLists($declaredMimeType, $fileName, $declaredMimeType, $detectedMime, $config);
        }

        // Reject when no type can be chosen.
        return [
            'isValid' => false,
            'error' => [
                'code' => 'MIME_TYPE_NOT_ALLOWED',
                'message' => 'Cannot verify file type for security reasons',
                'details' => self::omitNull([
                    'fileName' => $fileName,
                    'reason' => 'No MIME type to validate (no declared type, no extension, no detection)',
                    'declaredType' => $declaredMimeType,
                    'detectedType' => $detectedMime,
                    'allowedTypes' => $config['allowedTypes'] ?? null,
                    'deniedTypes' => $config['deniedTypes'] ?? null,
                ]),
            ],
        ];
    }

    /**
     * @param SecurityConfig $config
     * @return ValidationResult
     */
    private static function buildNotAllowedError(string $fileName, string $declaredType, ?string $detectedType, string $rejectedType, array $config): array
    {
        return [
            'isValid' => false,
            'error' => [
                'code' => 'MIME_TYPE_NOT_ALLOWED',
                'message' => "File type '{$rejectedType}' is not allowed",
                'details' => self::omitNull([
                    'fileName' => $fileName,
                    'declaredType' => $declaredType,
                    'detectedType' => $detectedType,
                    'finalType' => $rejectedType,
                    'allowedTypes' => $config['allowedTypes'] ?? null,
                    'deniedTypes' => $config['deniedTypes'] ?? null,
                    'reason' => 'MIME type is in the denied list',
                ]),
            ],
        ];
    }

    /**
     * `undefined` values are dropped by JSON serialization.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private static function omitNull(array $details): array
    {
        return array_filter($details, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return list<mixed>
     */
    private static function toList(mixed $files): array
    {
        if (is_array($files) && array_is_list($files)) {
            return $files;
        }

        return [$files];
    }

    /** @return list<ValidationResult> */
    public static function validateFiles(mixed $files, Strapi $strapi): array
    {
        $filesArray = self::toList($files);

        if ($filesArray === []) {
            return [];
        }

        $config = $strapi->config()->get('plugin::upload.security', []);
        if (!is_array($config)) {
            $config = [];
        }
        $allowed = $config['allowedTypes'] ?? null;
        if ($allowed !== null && $allowed !== false && (!is_array($allowed) || !array_is_list($allowed) || array_filter($allowed, static fn (mixed $item): bool => !is_string($item)) !== [])) {
            throw new ApplicationError('Invalid configuration: allowedTypes must be an array of strings.');
        }

        $denied = $config['deniedTypes'] ?? null;
        if ($denied !== null && $denied !== false && (!is_array($denied) || !array_is_list($denied) || array_filter($denied, static fn (mixed $item): bool => !is_string($item)) !== [])) {
            throw new ApplicationError('Invalid configuration: deniedTypes must be an array of strings.');
        }

        if (($config['allowedTypes'] ?? null) === null && ($config['deniedTypes'] ?? null) === null) {
            $strapi->log()->warning('No upload security configuration found. Consider configuring plugin.upload.security for enhanced file validation.');
            // Do not return; we still run validation so MIME detection runs and stored file gets detected type when possible
        }

        $results = [];
        foreach ($filesArray as $index => $file) {
            try {
                if (!$file instanceof \ArrayAccess && !is_array($file)) {
                    throw new \TypeError('Invalid file');
                }
                /** @var \ArrayAccess<string, mixed>|array<string, mixed> $file */
                $results[] = self::validateFile($file, $config, $strapi);
            } catch (\Throwable $error) {
                $fileName = self::fileNameOf($file);
                $strapi->log()->error('Unexpected error during file validation', [
                    'fileIndex' => $index,
                    'fileName' => $fileName,
                    'error' => $error->getMessage(),
                ]);

                $results[] = [
                    'isValid' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => "Validation failed for file at index {$index}",
                        'details' => self::omitNull([
                            'index' => $index,
                            'fileName' => $fileName,
                            'originalError' => $error->getMessage(),
                        ]),
                    ],
                ];
            }
        }

        return $results;
    }

    private static function fileNameOf(mixed $file): ?string
    {
        if (!$file instanceof \ArrayAccess && !is_array($file)) {
            return null;
        }
        $name = $file['name'] ?? $file['originalname'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * @return array{validFiles: list<\ArrayObject<string, mixed>>, validFileNames: list<string>, errors: list<ErrorDetail>}
     */
    public static function enforceUploadSecurity(mixed $files, Strapi $strapi): array
    {
        $validationResults = self::validateFiles($files, $strapi);
        $filesArray = self::toList($files);

        $validFiles = [];
        $validFileNames = [];
        $errors = [];

        foreach ($validationResults as $index => $result) {
            $file = $filesArray[$index];
            if ($result['isValid']) {
                if (!$file instanceof \ArrayObject) {
                    $file = new \ArrayObject(is_array($file) ? $file : []);
                }
                // Enrich file with detected MIME type for use in storage
                if (isset($result['detectedMime'])) {
                    $file['detectedMimeType'] = $result['detectedMime'];
                }
                $validFiles[] = $file;
                $name = $file['originalFilename'] ?? $file['name'] ?? null;
                $validFileNames[] = is_string($name) ? $name : '';
            } elseif (isset($result['error'])) {
                $errors[] = [
                    'file' => $file,
                    'originalIndex' => $index,
                    'error' => $result['error'],
                ];
            } else {
                // Handle case where validation failed but no error details are provided
                $errors[] = [
                    'file' => $file,
                    'originalIndex' => $index,
                    'error' => [
                        'code' => 'UNKNOWN_ERROR',
                        'message' => 'File validation failed for unknown reason',
                        'details' => self::omitNull([
                            'index' => $index,
                            'fileName' => self::fileNameOf($file),
                        ]),
                    ],
                ];
            }
        }

        return ['validFiles' => $validFiles, 'validFileNames' => $validFileNames, 'errors' => $errors];
    }

    /**
     * Prepare files and body for upload by enforcing security and parsing fileInfo
     *
     * @return PrepareUploadResult
     */
    public static function prepareUploadRequest(mixed $filesInput, mixed $body, Strapi $strapi): array
    {
        $securityResults = self::enforceUploadSecurity($filesInput, $strapi);

        $filteredBody = $body;
        if (is_array($body) && !empty($body['fileInfo'])) {
            // Parse JSON strings in fileInfo
            $parsedFileInfo = $body['fileInfo'];
            if (is_array($body['fileInfo']) && array_is_list($body['fileInfo'])) {
                $parsedFileInfo = array_map(static fn (mixed $fi): mixed => is_string($fi) ? self::jsonParse($fi) : $fi, $body['fileInfo']);
            } elseif (is_string($body['fileInfo'])) {
                $parsedFileInfo = self::jsonParse($body['fileInfo']);
            }

            // Filter fileInfo by index - only keep entries for files that passed validation
            if (is_array($parsedFileInfo) && array_is_list($parsedFileInfo)) {
                $invalidIndices = array_map(static fn (array $e): int => $e['originalIndex'], $securityResults['errors']);
                $filteredFileInfo = array_values(array_filter(
                    $parsedFileInfo,
                    static fn (int $index): bool => !in_array($index, $invalidIndices, true),
                    ARRAY_FILTER_USE_KEY,
                ));

                $filteredBody = [...$body, 'fileInfo' => count($filteredFileInfo) === 1 ? $filteredFileInfo[0] : $filteredFileInfo];
            } else {
                $filteredBody = [...$body, 'fileInfo' => $parsedFileInfo];
            }
        }

        // Map errors to simplified format
        $uploadErrors = array_map(static function (array $e): array {
            $file = $e['file'];
            $name = null;
            if ($file instanceof \ArrayAccess || is_array($file)) {
                $name = ($file['originalFilename'] ?? null) ?: ($file['name'] ?? null);
            }

            return [
                'name' => is_string($name) && $name !== '' ? $name : 'unknown',
                'message' => $e['error']['message'],
            ];
        }, $securityResults['errors']);

        return [
            'validFiles' => $securityResults['validFiles'],
            'filteredBody' => $filteredBody,
            'errors' => $uploadErrors,
        ];
    }

    /** `JSON.parse`: a SyntaxError surfaces as a 500, as upstream */
    private static function jsonParse(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
