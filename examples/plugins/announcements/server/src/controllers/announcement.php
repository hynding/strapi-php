<?php

declare(strict_types=1);

namespace StrapiPlugin\Announcements\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ValidationError;
use StrapiPlugin\Announcements\Generated\Announcement as AnnouncementEntry;
use StrapiPlugin\Announcements\Services\Announcement as AnnouncementService;

/** Mirrors controllers/announcement.ts; every action in Generated\RouteHandlers must exist here. */
final readonly class Announcement
{
    private const string PLUGIN_ID = 'announcements';

    public function __construct(private Strapi $strapi)
    {
    }

    /**
     * What the content API exposes of an announcement.
     *
     * @return array{documentId: string, title: string, body: ?string, level: string, createdAt: string}
     */
    private static function toPublic(AnnouncementEntry $a): array
    {
        return [
            'documentId' => $a->documentId,
            'title' => $a->title,
            'body' => $a->body,
            'level' => $a->level->value,
            'createdAt' => $a->createdAt,
        ];
    }

    private function service(): AnnouncementService
    {
        $service = $this->strapi->plugin(self::PLUGIN_ID)->service('announcement');
        if (!$service instanceof AnnouncementService) {
            throw new \LogicException('plugin::announcements.announcement service is not ' . AnnouncementService::class);
        }

        return $service;
    }

    /**
     * GET /api/announcements/active?limit=n — `limit` defaults to, and is capped by, config `maxActive`.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{limit: int}}
     */
    public function active(Context $ctx): array
    {
        $max = $this->strapi->plugin(self::PLUGIN_ID)->config('maxActive');
        $max = is_int($max) ? $max : 1;
        $raw = $ctx->query()['limit'] ?? null;

        $limit = $max;
        if ($raw !== null) {
            if (!is_string($raw) || preg_match('/^[1-9]\d*$/', $raw) !== 1) {
                throw new ValidationError('limit must be a positive integer', ['limit' => $raw]);
            }
            $limit = min((int) $raw, $max);
        }

        $rows = $this->service()->findActive($limit);

        return ['data' => array_map(self::toPublic(...), $rows), 'meta' => ['limit' => $limit]];
    }

    /**
     * GET /announcements/summary (admin)
     *
     * @return array{data: array{total: int, active: int, byLevel: array<string, int>}}
     */
    public function summary(Context $ctx): array
    {
        return ['data' => $this->service()->summary()];
    }
}
