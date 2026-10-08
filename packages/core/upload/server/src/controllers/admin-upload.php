<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Upload\Constants;
use Strapi\Upload\Controllers\Utils\FindEntityAndCheckPermissions;
use Strapi\Upload\Controllers\Validation\Admin\Upload as UploadValidation;
use Strapi\Upload\Services\Upload as UploadService;
use Strapi\Upload\Utils\MimeValidation;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/controllers/admin-upload.ts.
 *
 * `uploadFromUrls` writes its Server-Sent Events into the response body, which is sent when the
 * last URL is processed (upstream flushes each event as it is written).
 */
final class AdminUpload
{
    /**
     * Minimum delay between two `file:progress` frames for the same URL.
     */
    private const int URL_FETCH_PROGRESS_INTERVAL_MS = 200;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function bulkUpdateFileInfo(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');
        $user = $ctx->state()->user();
        $body = $ctx->requestBody();

        ['updates' => $updates] = UploadValidation::validateBulkUpdateBody($this->strapi, $body);
        $uploadService = Utils::getService('upload', $this->strapi);

        $results = [];
        foreach ($updates as ['id' => $id, 'fileInfo' => $fileInfo]) {
            ['pm' => $pm] = FindEntityAndCheckPermissions::findEntityAndCheckPermissions(
                $this->strapi,
                $userAbility,
                Constants::ACTIONS['update'],
                Constants::FILE_MODEL_UID,
                $id,
            );

            $updated = $uploadService->updateFileInfo($id, $fileInfo, ['user' => $user]);

            // Sign file urls for private providers
            $signedFile = Utils::getService('file', $this->strapi)->signFileUrls($updated);

            $results[] = $pm->sanitizeOutput($signedFile, ['action' => Constants::ACTIONS['read']]);
        }

        $ctx->setBody($results);
    }

    private static function fileId(Context $ctx): mixed
    {
        return $ctx->params()['id'] ?? ($ctx->query()['id'] ?? null);
    }

    /**
     * `PUT /upload/files/:id`
     *
     * Update the editable metadata (`fileInfo`) of an existing file. Also reached
     * through the `POST /upload` multiplexer, which delegates here with the id in
     * `ctx.query` — hence the dual read below, route params winning.
     */
    public function updateFileInfo(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');
        $user = $ctx->state()->user();
        $body = $ctx->requestBody();

        $id = self::fileId($ctx);

        if (!is_string($id)) {
            throw new ValidationError('File id is required');
        }

        $uploadService = Utils::getService('upload', $this->strapi);
        ['pm' => $pm] = FindEntityAndCheckPermissions::findEntityAndCheckPermissions(
            $this->strapi,
            $userAbility,
            Constants::ACTIONS['update'],
            Constants::FILE_MODEL_UID,
            $id,
        );

        $data = UploadValidation::validateUploadBody($this->strapi, $body);

        $file = $uploadService->updateFileInfo($id, is_array($data['fileInfo'] ?? null) ? $data['fileInfo'] : [], ['user' => $user]);

        // Sign file urls for private providers
        $signedFile = Utils::getService('file', $this->strapi)->signFileUrls($file);

        $ctx->setBody($pm->sanitizeOutput($signedFile, ['action' => Constants::ACTIONS['read']]));
    }

    /**
     * `POST /upload/files/:id/replace`
     *
     * Replace the binary content of an existing file. Also reached through the
     * `POST /upload` multiplexer, which delegates here with the id in `ctx.query`
     * — hence the dual read below, route params winning.
     */
    public function replaceFile(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');
        $user = $ctx->state()->user();
        $body = $ctx->requestBody();
        $filesInput = Utils::requestFiles($ctx->files());

        $id = self::fileId($ctx);

        if (!is_string($id)) {
            throw new ValidationError('File id is required');
        }

        $uploadService = Utils::getService('upload', $this->strapi);
        ['pm' => $pm] = FindEntityAndCheckPermissions::findEntityAndCheckPermissions(
            $this->strapi,
            $userAbility,
            Constants::ACTIONS['update'],
            Constants::FILE_MODEL_UID,
            $id,
        );

        if (is_array($filesInput) && count($filesInput) > 1) {
            throw new ApplicationError('Cannot replace a file with multiple ones');
        }

        $files = is_array($filesInput) ? ($filesInput[0] ?? null) : $filesInput;

        [
            'validFiles' => $validFiles,
            'filteredBody' => $filteredBody,
            'errors' => $validationErrors,
        ] = MimeValidation::prepareUploadRequest($files, $body, $this->strapi);
        if ($validFiles === []) {
            throw new ValidationError($validationErrors[0]['message'] ?? 'Validation failed');
        }

        $data = UploadValidation::validateUploadBody($this->strapi, $filteredBody);
        $replacedFile = $uploadService->replace($id, ['data' => $data, 'file' => $validFiles[0]], ['user' => $user]);

        // Regenerate AI metadata for image replacements so the alt text / caption
        // reflect the new file content. Mirrors the post-upload hook in
        // `uploadFiles`; failure is logged and swallowed to keep the replace flow
        // resilient when the AI provider is unavailable.
        $aiMetadataService = Utils::getService('aiMetadata', $this->strapi);
        $mime = $replacedFile['mime'] ?? null;
        if (is_string($mime) && str_starts_with($mime, 'image/') && $aiMetadataService->isEnabled()) {
            try {
                $replaced = [$replacedFile];
                $metadataResults = $aiMetadataService->processFiles($replaced);
                $aiMetadataService->updateFilesWithAIMetadata($replaced, $metadataResults, $user);
                $replacedFile = $replaced[0];
            } catch (\Throwable $error) {
                $this->strapi->log()->warning('AI metadata generation failed on replace, proceeding without it', [
                    'error' => $error->getMessage(),
                ]);
            }
        }

        // Sign file urls for private providers
        $signedFile = Utils::getService('file', $this->strapi)->signFileUrls($replacedFile);

        $ctx->setBody($pm->sanitizeOutput($signedFile, ['action' => Constants::ACTIONS['read']]));
    }

    public function uploadFiles(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');
        $user = $ctx->state()->user();
        $body = $ctx->requestBody();
        $files = Utils::requestFiles($ctx->files());

        $uploadService = Utils::getService('upload', $this->strapi);
        $pm = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'action' => Constants::ACTIONS['create'],
            'model' => Constants::FILE_MODEL_UID,
        ]);

        if (!$pm->isAllowed()) {
            $ctx->forbidden();

            return;
        }

        [
            'validFiles' => $validFiles,
            'filteredBody' => $filteredBody,
            'errors' => $validationErrors,
        ] = MimeValidation::prepareUploadRequest($files, $body, $this->strapi);
        if ($validFiles === []) {
            throw new ValidationError($validationErrors[0]['message'] ?? 'Validation failed');
        }

        $isMultipleFiles = count($validFiles) > 1;
        $data = UploadValidation::validateUploadBody($this->strapi, $filteredBody, $isMultipleFiles);

        $filesArray = $validFiles;

        $fileInfo = $data['fileInfo'] ?? null;
        if (is_array($fileInfo) && array_is_list($fileInfo) && count($filesArray) === count($fileInfo)) {
            // Reorder filesArray to match data.fileInfo order
            $alignedFilesArray = [];
            foreach ($fileInfo as $info) {
                foreach ($filesArray as $file) {
                    if ($file['originalFilename'] === (is_array($info) ? ($info['name'] ?? null) : null)) {
                        $alignedFilesArray[] = $file;
                        break;
                    }
                }
            }

            $filesArray = $alignedFilesArray;
        }

        // Upload files first to get thumbnails
        $uploadedFiles = $uploadService->upload(['data' => $data, 'files' => $filesArray], ['user' => $user]);
        foreach ($uploadedFiles as $file) {
            if (is_string($file['mime'] ?? null) && str_starts_with($file['mime'], 'image/')) {
                Utils::getService('metrics', $this->strapi)->trackUsage('didUploadImage');
                break;
            }
        }

        $aiMetadataService = Utils::getService('aiMetadata', $this->strapi);

        // AFTER upload - generate AI metadata for images
        if ($aiMetadataService->isEnabled()) {
            try {
                $metadataResults = $aiMetadataService->processFiles($uploadedFiles);
                // Update the uploaded files with AI metadata
                $aiMetadataService->updateFilesWithAIMetadata($uploadedFiles, $metadataResults, $user);
            } catch (\Throwable $error) {
                $this->strapi->log()->warning('AI metadata generation failed, proceeding without AI enhancements', [
                    'error' => $error->getMessage(),
                ]);
            }
        }

        // Sign file urls for private providers
        $fileService = Utils::getService('file', $this->strapi);
        $signedFiles = array_map(static fn (array $file): array => $fileService->signFileUrls($file), $uploadedFiles);

        $ctx->setBody($pm->sanitizeOutput($signedFiles, ['action' => Constants::ACTIONS['read']]));
        $ctx->setStatus(201);
    }

    /**
     * `POST /upload/files`
     *
     * Upload a single file and return the created File.
     */
    public function uploadFile(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');
        $user = $ctx->state()->user();
        $body = $ctx->requestBody();
        $files = Utils::requestFiles($ctx->files());

        $uploadService = Utils::getService('upload', $this->strapi);
        $pm = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'action' => Constants::ACTIONS['create'],
            'model' => Constants::FILE_MODEL_UID,
        ]);

        if (!$pm->isAllowed()) {
            $ctx->forbidden();

            return;
        }

        if (Utils::isEmptyFiles($files) || ($files instanceof \ArrayObject && ($files['size'] ?? 0) === 0)) {
            throw new ApplicationError('Files are empty');
        }

        // Accept a single file per request; ignore any extras defensively.
        $file = is_array($files) ? $files[0] : $files;
        if (!$file instanceof \ArrayObject) {
            throw new ApplicationError('Files are empty');
        }
        $fileName = is_string($file['originalFilename'] ?? null) && $file['originalFilename'] !== '' ? $file['originalFilename'] : 'unknown';

        // Parse the single fileInfo object from the multipart body.
        $fileInfo = [
            'name' => $fileName,
            'caption' => null,
            'alternativeText' => null,
            'folder' => null,
        ];
        $raw = is_array($body) ? ($body['fileInfo'] ?? null) : null;
        if (!empty($raw)) {
            if (is_string($raw)) {
                $fileInfo = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } elseif (is_array($raw) && array_is_list($raw)) {
                $fileInfo = is_string($raw[0]) ? json_decode($raw[0], true, 512, JSON_THROW_ON_ERROR) : $raw[0];
            } else {
                $fileInfo = $raw;
            }
        }

        // Validate this single file using security checks.
        ['validFiles' => $validFiles, 'errors' => $validationErrors] = MimeValidation::prepareUploadRequest(
            $file,
            ['fileInfo' => json_encode($fileInfo)],
            $this->strapi,
        );

        if ($validFiles === []) {
            throw new ValidationError(($validationErrors[0]['message'] ?? '') ?: 'Validation failed');
        }

        $data = UploadValidation::validateUploadBody($this->strapi, ['fileInfo' => $fileInfo], false);
        [$uploadedFile] = $uploadService->upload(['data' => $data, 'files' => [$validFiles[0]]], ['user' => $user]);

        if (is_string($uploadedFile['mime'] ?? null) && str_starts_with($uploadedFile['mime'], 'image/')) {
            Utils::getService('metrics', $this->strapi)->trackUsage('didUploadImage');
        }

        // No inline AI metadata generation — intentionally decoupled for the async job.

        // Sign file url for private providers.
        $signedFile = Utils::getService('file', $this->strapi)->signFileUrls($uploadedFile);

        $ctx->setBody($pm->sanitizeOutput($signedFile, ['action' => Constants::ACTIONS['read']]));
        $ctx->setStatus(201);
    }

    /**
     * `POST /upload/actions/upload-from-urls`
     *
     * Upload files from URLs with Server-Sent Events for per-file progress:
     * - file:fetching  — when starting to fetch a URL
     * - file:progress  — throttled byte progress of the remote → server temp file transfer
     * - file:uploading — when upload starts for a fetched file
     * - file:complete  — when a file is successfully uploaded
     * - file:error     — when a URL fetch or upload fails
     * - stream:complete — final summary with all results
     */
    public function uploadFromUrls(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');
        $user = $ctx->state()->user();
        $body = $ctx->requestBody();

        $uploadService = Utils::getService('upload', $this->strapi);
        $fileService = Utils::getService('file', $this->strapi);
        $pm = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'action' => Constants::ACTIONS['create'],
            'model' => Constants::FILE_MODEL_UID,
        ]);

        if (!$pm->isAllowed()) {
            $ctx->forbidden();

            return;
        }

        // Parse and validate request body
        $urls = is_array($body) ? ($body['urls'] ?? null) : null;
        $folderId = is_array($body) ? ($body['folderId'] ?? null) : null;

        if (!is_array($urls) || !array_is_list($urls) || $urls === []) {
            throw new ApplicationError('URLs are required');
        }

        if (count($urls) > 20) {
            throw new ApplicationError('Maximum 20 URLs allowed per request');
        }

        // Take manual control of the response for SSE streaming
        $out = fopen('php://temp', 'w+b');
        if ($out === false) {
            throw new \RuntimeException('Cannot open the response stream');
        }
        $writeSSE = static function (string $event, array $data) use ($out): void {
            fwrite($out, "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n\n");
        };

        $total = count($urls);
        $uploadErrors = [];
        $successfulFiles = [];

        // Create temp directory for fetched files
        $tmpWorkingDirectory = UploadService::mkdtemp(sys_get_temp_dir() . '/strapi-url-upload-');
        $sizeLimit = $this->strapi->config()->get('plugin::upload.sizeLimit');
        $sizeLimit = is_numeric($sizeLimit) ? $sizeLimit + 0 : null;

        try {
            // Process each URL sequentially
            foreach ($urls as $i => $url) {
                $url = is_scalar($url) ? (string) $url : '';

                $writeSSE('file:fetching', ['url' => $url, 'index' => $i, 'total' => $total]);

                try {
                    // `fetchUrlToInputFile` reports raw, unthrottled progress — coalescing it into a
                    // sane frame rate is the caller's job. State is per URL: the loop is sequential.
                    $lastProgressAt = 0.0;
                    $hasAnnouncedSize = false;
                    $hasReachedTotal = false;

                    $onFetchProgress = static function (array $progress) use (&$lastProgressAt, &$hasAnnouncedSize, &$hasReachedTotal, $writeSSE, $i): void {
                        $bytesWritten = $progress['bytesWritten'];
                        $totalBytes = $progress['totalBytes'];
                        // The frame that reaches the total always goes out, or a throttled last chunk
                        // leaves the row short of 100% until `file:complete`.
                        $isFinal = !$hasReachedTotal && $totalBytes !== null && $bytesWritten >= $totalBytes;
                        $now = microtime(true) * 1000;
                        if ($hasAnnouncedSize && !$isFinal && $now - $lastProgressAt < self::URL_FETCH_PROGRESS_INTERVAL_MS) {
                            return;
                        }

                        $hasAnnouncedSize = true;
                        $hasReachedTotal = $hasReachedTotal || $isFinal;
                        $lastProgressAt = $now;

                        $writeSSE('file:progress', [
                            'index' => $i,
                            'loadedBytes' => $bytesWritten,
                            'totalBytes' => $totalBytes,
                            'phase' => 'fetch',
                        ]);
                    };

                    // Fetch URL to temp file
                    ['file' => $file] = $fileService->fetchUrlToInputFile($url, $tmpWorkingDirectory, $sizeLimit, $onFetchProgress);
                    $fileName = (string) $file['originalFilename'];

                    $writeSSE('file:uploading', [
                        'name' => $fileName,
                        'index' => $i,
                        'total' => $total,
                        'size' => $file['size'],
                    ]);

                    // Validate using security checks
                    $fileInfo = [
                        'name' => $fileName,
                        'caption' => null,
                        'alternativeText' => null,
                        'folder' => $folderId,
                    ];

                    ['validFiles' => $validFiles, 'errors' => $validationErrors] = MimeValidation::prepareUploadRequest(
                        $file,
                        ['fileInfo' => json_encode($fileInfo)],
                        $this->strapi,
                    );

                    if ($validFiles === []) {
                        $errorMessage = ($validationErrors[0]['message'] ?? '') ?: 'Validation failed';
                        $uploadErrors[] = ['name' => $fileName, 'message' => $errorMessage];
                        $writeSSE('file:error', ['name' => $fileName, 'url' => $url, 'index' => $i, 'message' => $errorMessage]);
                    } else {
                        // Upload the file
                        $data = UploadValidation::validateUploadBody($this->strapi, ['fileInfo' => $fileInfo], false);
                        [$uploadedFile] = $uploadService->upload(['data' => $data, 'files' => [$validFiles[0]]], ['user' => $user]);

                        // Sign file url
                        $signedFile = $fileService->signFileUrls($uploadedFile);
                        $successfulFiles[] = $signedFile;

                        $writeSSE('file:complete', ['name' => $fileName, 'index' => $i, 'file' => $signedFile]);
                    }
                } catch (\Throwable $error) {
                    $errorMessage = $error->getMessage();
                    $uploadErrors[] = ['name' => $url, 'message' => $errorMessage];
                    $writeSSE('file:error', ['url' => $url, 'index' => $i, 'message' => $errorMessage]);
                }
            }

            // Track image upload metric once if any images were uploaded
            foreach ($successfulFiles as $successfulFile) {
                if (is_string($successfulFile['mime'] ?? null) && str_starts_with($successfulFile['mime'], 'image/')) {
                    Utils::getService('metrics', $this->strapi)->trackUsage('didUploadImage');
                    break;
                }
            }

            // Send final stream summary
            $writeSSE('stream:complete', [
                'data' => $pm->sanitizeOutput($successfulFiles, ['action' => Constants::ACTIONS['read']]),
                'errors' => $uploadErrors,
            ]);
        } finally {
            // Clean up temp directory
            UploadService::removeDirectory($tmpWorkingDirectory);
        }

        rewind($out);
        $ctx->setStatus(200);
        $ctx->setHeader('Content-Type', 'text/event-stream');
        $ctx->setHeader('Cache-Control', 'no-cache');
        $ctx->setHeader('Connection', 'keep-alive');
        $ctx->setType('text/event-stream');
        $ctx->setBody(\Nyholm\Psr7\Stream::create($out));
    }

    // TODO: split into multiple endpoints
    public function upload(Context $ctx): void
    {
        $id = $ctx->query()['id'] ?? null;
        $files = Utils::requestFiles($ctx->files());

        if (Utils::isEmptyFiles($files) || ($files instanceof \ArrayObject && ($files['size'] ?? 0) === 0)) {
            if ($id) {
                $this->updateFileInfo($ctx);

                return;
            }

            throw new ApplicationError('Files are empty');
        }

        if ($id) {
            $this->replaceFile($ctx);
        } else {
            $this->uploadFiles($ctx);
        }
    }
}
