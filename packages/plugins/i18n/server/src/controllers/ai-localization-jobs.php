<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Controllers;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Types\Core\Context;

/** Port of server/src/controllers/ai-localization-jobs.ts (`createAILocalizationJobsController`). */
final class AiLocalizationJobs
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Get a job for a singleType using the contentType
     * There is only 1 job per contentType
     */
    public function getJobForSingleType(Context $ctx): void
    {
        if (Utils::aiLocalizations($this->strapi)->isEnabled() === false) {
            $ctx->notFound();

            return;
        }

        $contentType = $ctx->params()['contentType'] ?? null;

        if ($contentType === null || $contentType === '') {
            $ctx->badRequest('contentType is required');

            return;
        }

        try {
            $job = Utils::aiLocalizationJobs($this->strapi)->getJobByContentType($contentType);

            $ctx->setBody([
                'data' => $job,
            ]);
        } catch (\Throwable $error) {
            $this->strapi->log()->error('[AI Localizations Jobs] Error fetching job:', ['error' => $error]);
            $ctx->internalServerError('Failed to fetch AI localizations job');
        }
    }

    /**
     * Get a job for a collectionType using the documentId
     * There is only 1 job per documentId
     */
    public function getJobForCollectionType(Context $ctx): void
    {
        if (Utils::aiLocalizations($this->strapi)->isEnabled() === false) {
            $ctx->notFound();

            return;
        }

        $documentId = $ctx->params()['documentId'] ?? null;
        $contentType = $ctx->params()['contentType'] ?? null;

        if ($documentId === null || $documentId === '' || $contentType === null || $contentType === '') {
            $ctx->badRequest('Document ID and contentType are required');

            return;
        }

        try {
            $job = Utils::aiLocalizationJobs($this->strapi)->getJobByDocument($contentType, $documentId);

            $ctx->setBody([
                'data' => $job,
            ]);
        } catch (\Throwable $error) {
            $this->strapi->log()->error('[AI Localizations Jobs] Error fetching job:', ['error' => $error]);
            $ctx->internalServerError('Failed to fetch AI localizations job');
        }
    }
}
