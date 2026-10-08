<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Models\AiLocalizationJob;

/** Port of server/src/services/ai-localization-jobs.ts (`createAILocalizationJobsService`). */
final class AiLocalizationJobs
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Create a new AI localizations job or update an existing one for a document
     * Ensures only one job exists per document
     *
     * @param array{documentId: string, contentType: string, sourceLocale: string, targetLocales: list<string>, status?: 'processing'|'completed'|'failed'} $params
     * @return array<string, mixed>|null
     */
    public function upsertJobForDocument(array $params): ?array
    {
        $documentId = $params['documentId'];
        $contentType = $params['contentType'];
        $sourceLocale = $params['sourceLocale'];
        $targetLocales = $params['targetLocales'];
        $status = $params['status'] ?? 'processing';

        // Check if job already exists for this document
        $existingJob = $this->getJobByDocument($contentType, $documentId);

        if ($existingJob !== null) {
            $this->strapi->log()->info("[AI Localizations Job] Updated existing job for document {$documentId} with status: {$status}");

            // Update existing job with new data and status
            return $this->strapi->db()->query(AiLocalizationJob::AI_LOCALIZATION_JOB_UID)->update([
                'where' => ['id' => $existingJob['id'] ?? null],
                'data' => [
                    'contentType' => $contentType,
                    'sourceLocale' => $sourceLocale,
                    'targetLocales' => $targetLocales,
                    'status' => $status,
                    'updatedAt' => new \DateTimeImmutable(),
                ],
            ]);
        }

        $this->strapi->log()->info("[AI Localizations Job] Created new job for document {$documentId} with status: {$status}");

        // Create new AI localizations job
        return $this->strapi->db()->query(AiLocalizationJob::AI_LOCALIZATION_JOB_UID)->create([
            'data' => [
                'contentType' => $contentType,
                'relatedDocumentId' => $documentId,
                'sourceLocale' => $sourceLocale,
                'targetLocales' => $targetLocales,
                'status' => $status,
                'createdAt' => new \DateTimeImmutable(),
                'updatedAt' => new \DateTimeImmutable(),
            ],
        ]);
    }

    /**
     * Get job by document ID
     *
     * @return array<string, mixed>|null
     */
    public function getJobByDocument(string $contentType, string $documentId): ?array
    {
        return $this->strapi->db()->query(AiLocalizationJob::AI_LOCALIZATION_JOB_UID)->findOne([
            'where' => [
                'relatedDocumentId' => $documentId,
                'contentType' => $contentType,
            ],
        ]);
    }

    /**
     * Get job by content type
     *
     * @return array<string, mixed>|null
     */
    public function getJobByContentType(string $contentType): ?array
    {
        return $this->strapi->db()->query(AiLocalizationJob::AI_LOCALIZATION_JOB_UID)->findOne([
            'where' => [
                'contentType' => $contentType,
            ],
        ]);
    }
}
