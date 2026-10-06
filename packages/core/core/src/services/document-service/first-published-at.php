<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Types\Schema\Schema;

/** Port of services/document-service/first-published-at.ts. */
final class FirstPublishedAt
{
    /**
     * @param array<string, mixed> $draft
     * @param callable(array<string, mixed>, array<string, mixed>): (array<string, mixed>|null) $update entries.update
     * @return array<string, mixed>
     */
    public static function addFirstPublishedAtToDraft(array $draft, callable $update, Schema $contentType): array
    {
        if (!$contentType->hasAttribute('firstPublishedAt')) {
            return $draft;
        }

        if (!empty($draft['firstPublishedAt'])) {
            return $draft;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');

        // Persist to the draft DB row but keep the populated draft (entries.update returns an unpopulated row)
        $updatedDraft = $update($draft, ['data' => ['firstPublishedAt' => $now]]) ?? [];

        return [
            ...$draft,
            'firstPublishedAt' => $now,
            ...(!empty($updatedDraft['updatedAt']) ? ['updatedAt' => $updatedDraft['updatedAt']] : []),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function filterDataFirstPublishedAt(array $params): array
    {
        if (!empty($params['data']['firstPublishedAt'])) {
            return [...$params, 'data' => [...$params['data'], 'firstPublishedAt' => null]];
        }

        return $params;
    }
}
