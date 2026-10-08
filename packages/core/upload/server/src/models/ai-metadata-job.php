<?php

declare(strict_types=1);

namespace Strapi\Upload\Models;

/** Port of server/src/models/ai-metadata-job.ts: a raw database model (no content type). */
final class AiMetadataJob
{
    public const string AI_METADATA_JOB_UID = 'plugin::upload.ai-metadata-job';

    /** @return array<string, mixed> */
    public static function aiMetadataJob(): array
    {
        return [
            'uid' => self::AI_METADATA_JOB_UID,
            'tableName' => 'strapi_ai_metadata_jobs',
            'singularName' => 'ai-metadata-job',
            'attributes' => [
                'id' => [
                    'type' => 'increments',
                ],
                'status' => [
                    'type' => 'enumeration',
                    'enum' => ['processing', 'completed', 'failed'],
                    'column' => ['notNullable' => true],
                ],
                'createdAt' => [
                    'type' => 'datetime',
                    'default' => static fn (): \DateTimeImmutable => new \DateTimeImmutable(),
                ],
                'completedAt' => [
                    'type' => 'datetime',
                    'default' => null,
                ],
            ],
        ];
    }
}
