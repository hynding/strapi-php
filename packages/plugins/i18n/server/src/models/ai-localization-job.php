<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Models;

/** Port of server/src/models/ai-localization-job.ts: a raw database model (no content type). */
final class AiLocalizationJob
{
    public const string AI_LOCALIZATION_JOB_UID = 'plugin::i18n.ai-localization-job';

    /** @return array<string, mixed> */
    public static function aiLocalizationJob(): array
    {
        return [
            'uid' => self::AI_LOCALIZATION_JOB_UID,
            'tableName' => 'strapi_ai_localization_jobs',
            'singularName' => 'ai-localization-job',
            'attributes' => [
                'id' => [
                    'type' => 'increments',
                ],
                'contentType' => [
                    'type' => 'string',
                    'column' => ['notNullable' => true],
                ],
                'relatedDocumentId' => [
                    'type' => 'string',
                    'column' => ['notNullable' => true],
                ],
                'sourceLocale' => [
                    'type' => 'string',
                    'column' => ['notNullable' => true],
                ],
                'targetLocales' => [
                    'type' => 'json',
                    'column' => ['notNullable' => true],
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
                'updatedAt' => [
                    'type' => 'datetime',
                    'default' => static fn (): \DateTimeImmutable => new \DateTimeImmutable(),
                ],
            ],
        ];
    }
}
