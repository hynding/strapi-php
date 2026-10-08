<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Upload\Constants;
use Strapi\Upload\Controllers\Validation\ContentApi\Upload as UploadValidation;
use Strapi\Upload\Utils\MimeValidation;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/controllers/content-api.ts. */
final class ContentApi
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function sanitizeOutput(mixed $data, Context $ctx): mixed
    {
        $schema = $this->strapi->getModel(Constants::FILE_MODEL_UID);
        $auth = $ctx->state()->auth();

        return $this->strapi->contentAPI()->sanitize()->output($data, $schema, ['auth' => $auth]);
    }

    /** @param array<string, mixed> $data */
    private function validateQuery(array $data, Context $ctx): void
    {
        $schema = $this->strapi->getModel(Constants::FILE_MODEL_UID);

        $this->strapi->contentAPI()->validate()->query($data, $schema, ['auth' => $ctx->state()->auth(), 'route' => $ctx->state()->route()]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeQuery(array $data, Context $ctx): array
    {
        $schema = $this->strapi->getModel(Constants::FILE_MODEL_UID);

        return $this->strapi->contentAPI()->sanitize()->query($data, $schema, ['auth' => $ctx->state()->auth(), 'route' => $ctx->state()->route()]);
    }

    public function find(Context $ctx): void
    {
        $this->validateQuery($ctx->query(), $ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx->query(), $ctx);

        $files = Utils::getService('upload', $this->strapi)->findMany($sanitizedQuery);

        $fileService = Utils::getService('file', $this->strapi);
        $signedFiles = array_map(static fn (array $file): array => $fileService->signFileUrls($file), $files);

        $ctx->setBody($this->sanitizeOutput($signedFiles, $ctx));
    }

    public function findPage(Context $ctx): void
    {
        $this->validateQuery($ctx->query(), $ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx->query(), $ctx);

        ['results' => $results, 'pagination' => $pagination] = Utils::getService('upload', $this->strapi)->findAndCountPage($sanitizedQuery);

        $data = $this->sanitizeOutput($results, $ctx);

        $ctx->setBody(['data' => $data, 'meta' => ['pagination' => $pagination]]);
    }

    public function findOne(Context $ctx): void
    {
        $id = (string) $ctx->param('id');

        $this->validateQuery($ctx->query(), $ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx->query(), $ctx);

        $file = Utils::getService('upload', $this->strapi)->findOne($id, $sanitizedQuery['populate'] ?? null);

        if ($file === null) {
            $ctx->notFound('file.notFound');

            return;
        }

        $signedFile = Utils::getService('file', $this->strapi)->signFileUrls($file);

        $ctx->setBody($this->sanitizeOutput($signedFile, $ctx));
    }

    public function destroy(Context $ctx): void
    {
        $id = (string) $ctx->param('id');

        $file = Utils::getService('upload', $this->strapi)->findOne($id);

        if ($file === null) {
            $ctx->notFound('file.notFound');

            return;
        }

        Utils::getService('upload', $this->strapi)->remove($file);

        $signedFile = Utils::getService('file', $this->strapi)->signFileUrls($file);

        $ctx->setBody($this->sanitizeOutput($signedFile, $ctx));
    }

    public function updateFileInfo(Context $ctx): void
    {
        $id = $ctx->query()['id'] ?? null;
        $body = $ctx->requestBody();
        $data = UploadValidation::validateUploadBody($body);

        if (!$id || (!is_string($id) && !is_int($id))) {
            throw new ValidationError('File id is required and must be a single value');
        }

        $result = Utils::getService('upload', $this->strapi)->updateFileInfo($id, is_array($data['fileInfo'] ?? null) ? $data['fileInfo'] : []);

        $signedResult = Utils::getService('file', $this->strapi)->signFileUrls($result);

        $ctx->setBody($this->sanitizeOutput($signedResult, $ctx));
    }

    public function replaceFile(Context $ctx): void
    {
        $id = $ctx->query()['id'] ?? null;
        $body = $ctx->requestBody();
        $filesInput = Utils::requestFiles($ctx->files());

        // cannot replace with more than one file
        if (is_array($filesInput) && count($filesInput) > 1) {
            throw new ValidationError('Cannot replace a file with multiple ones');
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

        if (!$id || (!is_string($id) && !is_int($id))) {
            throw new ValidationError('File id is required and must be a single value');
        }

        $data = UploadValidation::validateUploadBody($filteredBody);

        $replacedFiles = Utils::getService('upload', $this->strapi)->replace($id, ['data' => $data, 'file' => $validFiles[0]]);

        $signedFiles = Utils::getService('file', $this->strapi)->signFileUrls($replacedFiles);

        $ctx->setBody($this->sanitizeOutput($signedFiles, $ctx));
    }

    public function uploadFiles(Context $ctx): void
    {
        $body = $ctx->requestBody();
        $filesInput = Utils::requestFiles($ctx->files());

        [
            'validFiles' => $validFiles,
            'filteredBody' => $filteredBody,
            'errors' => $validationErrors,
        ] = MimeValidation::prepareUploadRequest($filesInput, $body, $this->strapi);
        if ($validFiles === []) {
            throw new ValidationError($validationErrors[0]['message'] ?? 'Validation failed');
        }

        $isMultipleFiles = count($validFiles) > 1;
        $data = UploadValidation::validateUploadBody($filteredBody, $isMultipleFiles);

        $apiUploadFolderService = Utils::getService('api-upload-folder', $this->strapi);

        $apiUploadFolder = $apiUploadFolderService->getAPIUploadFolder();

        if ($isMultipleFiles) {
            $fileInfo = is_array($data['fileInfo'] ?? null) ? $data['fileInfo'] : [];
            $data['fileInfo'] = array_map(
                static fn (int $i): array => [...(is_array($fileInfo[$i] ?? null) ? $fileInfo[$i] : []), 'folder' => $apiUploadFolder['id']],
                array_keys($validFiles),
            );
        } else {
            $data['fileInfo'] = [...(is_array($data['fileInfo'] ?? null) ? $data['fileInfo'] : []), 'folder' => $apiUploadFolder['id']];
        }

        $uploadedFiles = Utils::getService('upload', $this->strapi)->upload([
            'data' => $data,
            'files' => $validFiles,
        ]);

        $fileService = Utils::getService('file', $this->strapi);
        $signedFiles = array_map(static fn (array $file): array => $fileService->signFileUrls($file), $uploadedFiles);

        $ctx->setBody($this->sanitizeOutput($signedFiles, $ctx));
        $ctx->setStatus(201);
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

            throw new ValidationError('Files are empty');
        }

        if ($id) {
            $this->replaceFile($ctx);
        } else {
            $this->uploadFiles($ctx);
        }
    }
}
