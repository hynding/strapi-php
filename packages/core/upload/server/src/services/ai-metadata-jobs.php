<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Upload\Models\AiMetadataJob;

/** Port of server/src/services/ai-metadata-jobs.ts. */
final class AiMetadataJobs
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function createJob(): int
    {
        $job = $this->strapi->db()->query(AiMetadataJob::AI_METADATA_JOB_UID)->create([
            'data' => [
                'status' => 'processing',
                'createdAt' => new \DateTimeImmutable(),
            ],
        ]);

        return (int) ($job['id'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    public function getJob(int $jobId): ?array
    {
        $job = $this->strapi->db()->query(AiMetadataJob::AI_METADATA_JOB_UID)->findOne([
            'where' => ['id' => $jobId],
        ]);

        return is_array($job) ? $job : null;
    }

    /** @param array<string, mixed> $updates */
    public function updateJob(int $jobId, array $updates): void
    {
        $this->strapi->db()->query(AiMetadataJob::AI_METADATA_JOB_UID)->update([
            'where' => ['id' => $jobId],
            'data' => $updates,
        ]);
    }

    public function deleteJob(int $jobId): void
    {
        $this->strapi->db()->query(AiMetadataJob::AI_METADATA_JOB_UID)->delete([
            'where' => ['id' => $jobId],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function getLatestActiveJob(): ?array
    {
        // Return the most recent job, regardless of status
        // This allows the frontend to see completed/failed status
        $job = $this->strapi->db()->query(AiMetadataJob::AI_METADATA_JOB_UID)->findOne([
            'orderBy' => ['createdAt' => 'desc'],
        ]);

        return is_array($job) ? $job : null;
    }
}
