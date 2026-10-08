<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Upload\Constants;
use Strapi\Upload\Controllers\Utils\FindEntityAndCheckPermissions;
use Strapi\Upload\Controllers\Validation\Admin\AiMetadata as AiMetadataValidation;
use Strapi\Upload\Utils\Utils;

/** Port of server/src/controllers/admin-file.ts. */
final class AdminFile
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * lodash `merge(object, source)`: `source` wins, plain objects merged deeply.
     *
     * @param array<string, mixed> $object
     * @param array<mixed> $source
     * @return array<string, mixed>
     */
    private static function merge(array $object, array $source): array
    {
        foreach ($source as $key => $value) {
            $key = (string) $key;
            $current = $object[$key] ?? null;
            $object[$key] = is_array($current) && is_array($value) && !array_is_list($value) && !array_is_list($current)
                ? self::merge($current, $value)
                : $value;
        }

        return $object;
    }

    /** @return array{results: mixed, pagination: mixed}|null */
    public function find(Context $ctx): ?array
    {
        $userAbility = $ctx->state()->get('userAbility');

        $defaultQuery = ['populate' => ['folder' => true]];

        $pm = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'action' => Constants::ACTIONS['read'],
            'model' => Constants::FILE_MODEL_UID,
        ]);

        if (!$pm->isAllowed()) {
            $ctx->forbidden();

            return null;
        }

        // validate the incoming user query params
        $pm->validateQuery($ctx->query());

        // Start by sanitizing the incoming query
        $query = $pm->sanitizeQuery($ctx->query());
        // Add the default query which should not be validated or sanitized
        $query = self::merge($defaultQuery, is_array($query) ? $query : []);
        // Add the dynamic filters based on permissions' conditions
        $query = $pm->addPermissionsQueryTo($query);

        ['results' => $files, 'pagination' => $pagination] = Utils::getService('upload', $this->strapi)->findPage($query);

        // Sign file urls for private providers
        $fileService = Utils::getService('file', $this->strapi);
        $signedFiles = array_map(static fn (array $file): array => $fileService->signFileUrls($file), $files);

        $sanitizedFiles = $pm->sanitizeOutput($signedFiles);

        return ['results' => $sanitizedFiles, 'pagination' => $pagination];
    }

    public function findOne(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');
        $id = (string) $ctx->param('id');

        ['pm' => $pm, 'file' => $file] = FindEntityAndCheckPermissions::findEntityAndCheckPermissions(
            $this->strapi,
            $userAbility,
            Constants::ACTIONS['read'],
            Constants::FILE_MODEL_UID,
            $id,
        );

        $signedFile = Utils::getService('file', $this->strapi)->signFileUrls($file);
        $ctx->setBody($pm->sanitizeOutput($signedFile));
    }

    public function destroy(Context $ctx): void
    {
        $id = (string) $ctx->param('id');
        $userAbility = $ctx->state()->get('userAbility');

        ['pm' => $pm, 'file' => $file] = FindEntityAndCheckPermissions::findEntityAndCheckPermissions(
            $this->strapi,
            $userAbility,
            Constants::ACTIONS['update'],
            Constants::FILE_MODEL_UID,
            $id,
        );

        $body = $pm->sanitizeOutput($file, ['action' => Constants::ACTIONS['read']]);
        Utils::getService('upload', $this->strapi)->remove($file);

        $ctx->setBody($body);
    }

    /**
     * `GET /upload/ai-metadata-jobs/pending-count`
     *
     * How many images a backfill job would have to process, i.e. those still
     * missing AI metadata, alongside the total image count.
     */
    public function getAIMetadataPendingCount(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');

        $pm = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'action' => Constants::ACTIONS['read'],
            'model' => Constants::FILE_MODEL_UID,
        ]);

        if (!$pm->isAllowed()) {
            $ctx->forbidden();

            return;
        }

        $aiMetadataService = Utils::getService('aiMetadata', $this->strapi);

        // Check if AI service is enabled
        if (!$aiMetadataService->isEnabled()) {
            $ctx->badRequest('AI Metadata service is not enabled');

            return;
        }

        try {
            ['imagesWithoutMetadataCount' => $imagesWithoutMetadataCount, 'totalImages' => $totalImages] = $aiMetadataService->countImagesWithoutMetadata();

            $ctx->setBody([
                'imagesWithoutMetadataCount' => $imagesWithoutMetadataCount,
                'totalImages' => $totalImages,
            ]);
        } catch (\Throwable $error) {
            $message = $error->getMessage() !== '' ? $error->getMessage() : 'Failed to get AI metadata count';

            $this->strapi->log()->error('Failed to get AI metadata count', [
                'message' => $message,
                'error' => $error,
            ]);

            $ctx->badRequest($message);
        }
    }

    /**
     * `POST /upload/ai-metadata-jobs`
     *
     * Create a backfill job that generates AI metadata for every image still
     * missing it, and return the job so the client can poll it. Processing runs
     * after the response is sent (upstream: detached from the request).
     */
    public function createAIMetadataJob(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');

        $pm = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'action' => Constants::ACTIONS['update'],
            'model' => Constants::FILE_MODEL_UID,
        ]);

        if (!$pm->isAllowed()) {
            $ctx->forbidden();

            return;
        }

        $aiMetadataService = Utils::getService('aiMetadata', $this->strapi);

        // Check if AI service is enabled
        if (!$aiMetadataService->isEnabled()) {
            $ctx->badRequest('AI Metadata service is not enabled');

            return;
        }

        try {
            // Get count first to check if there are images to process
            $result = $aiMetadataService->countImagesWithoutMetadata();

            if ($result['imagesWithoutMetadataCount'] === 0) {
                $ctx->setBody([
                    'count' => 0,
                    'message' => 'No images without metadata found',
                ]);

                return;
            }

            // Create job
            $jobService = Utils::getService('aiMetadataJobs', $this->strapi);
            $jobId = $jobService->createJob();

            // Start async processing (fire and forget): runs once the response has been sent
            $user = $ctx->state()->user();
            $strapi = $this->strapi;
            register_shutdown_function(static function () use ($aiMetadataService, $jobId, $user, $strapi): void {
                try {
                    $aiMetadataService->processExistingFiles($jobId, $user);
                } catch (\Throwable $err) {
                    $strapi->log()->error('AI metadata job failed:', ['exception' => $err]);
                }
            });

            // Return immediately with job ID
            $ctx->setBody([
                'jobId' => $jobId,
                'status' => 'pending',
            ]);
        } catch (\Throwable $error) {
            $message = $error->getMessage() !== '' ? $error->getMessage() : 'Failed to generate AI metadata';
            $cause = $error->getPrevious()?->getMessage();

            $this->strapi->log()->error('AI metadata generation failed in controller', [
                'message' => $message,
                'cause' => $cause,
                'error' => $error,
            ]);

            $ctx->badRequest($cause !== null ? "{$message}: {$cause}" : $message);
        }
    }

    /**
     * `POST /upload/actions/generate-ai-metadata`
     *
     * Generate AI metadata for the explicit selection of files in the body,
     * synchronously: the request resolves once every file has been processed and
     * reports the outcome per file, so a single bad id or a non-image in the
     * selection never fails the whole batch.
     *
     * For the whole-library backfill, see `createAIMetadataJob`.
     */
    public function generateAIMetadata(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');
        $body = $ctx->requestBody();

        $pm = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'action' => Constants::ACTIONS['update'],
            'model' => Constants::FILE_MODEL_UID,
        ]);

        if (!$pm->isAllowed()) {
            $ctx->forbidden();

            return;
        }

        ['fileIds' => $fileIds] = AiMetadataValidation::validateGenerateAIMetadataBody($body);

        $aiMetadataService = Utils::getService('aiMetadata', $this->strapi);

        if (!$aiMetadataService->isEnabled()) {
            $ctx->badRequest('AI Metadata service is not enabled');

            return;
        }

        $results = $aiMetadataService->generateForFiles($fileIds, $ctx->state()->user());

        $ctx->setBody(['data' => $results]);
    }

    /**
     * `GET /upload/ai-metadata-jobs/latest`
     *
     * The most recent still-active backfill job, so a client that reloads mid-run
     * can pick the progress bar back up. 404s when nothing is running.
     */
    public function getLatestAIMetadataJob(Context $ctx): void
    {
        if (Utils::getService('aiMetadata', $this->strapi)->isEnabled() === false) {
            $ctx->notFound();

            return;
        }

        $jobService = Utils::getService('aiMetadataJobs', $this->strapi);
        $job = $jobService->getLatestActiveJob();

        if ($job === null) {
            $ctx->notFound('No active job found');

            return;
        }

        $ctx->setBody($job);
    }
}
