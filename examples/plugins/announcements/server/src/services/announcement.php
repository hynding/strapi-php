<?php

declare(strict_types=1);

namespace StrapiPlugin\Announcements\Services;

use Strapi\Core\Strapi;
use StrapiPlugin\Announcements\Generated\Announcement as AnnouncementEntry;
use StrapiPlugin\Announcements\Generated\AnnouncementLevel;

/** Mirrors services/announcement.ts. */
final readonly class Announcement
{
    public function __construct(private Strapi $strapi)
    {
    }

    /** Severity is the position of the level in the schema's enum: info < warning < critical. */
    private static function severity(AnnouncementLevel $level): int
    {
        return (int) array_search($level, AnnouncementLevel::cases(), true);
    }

    /**
     * Active announcements: most severe first, newest first within a level, at most `$limit`.
     *
     * @return list<AnnouncementEntry>
     */
    public function findActive(int $limit): array
    {
        $rows = array_map(AnnouncementEntry::fromArray(...), $this->strapi->documents(AnnouncementEntry::UID)->findMany([
            'filters' => ['active' => true],
            'sort' => ['createdAt:desc', 'id:desc'],
        ]));

        usort($rows, static fn (AnnouncementEntry $a, AnnouncementEntry $b): int => self::severity($b->level) <=> self::severity($a->level));

        return array_slice($rows, 0, $limit);
    }

    /**
     * Counts for the admin page.
     *
     * @return array{total: int, active: int, byLevel: array<string, int>}
     */
    public function summary(): array
    {
        $documents = $this->strapi->documents(AnnouncementEntry::UID);

        $byLevel = [];
        foreach (AnnouncementLevel::cases() as $level) {
            $byLevel[$level->value] = $documents->count(['filters' => ['level' => $level->value]]);
        }

        return [
            'total' => $documents->count([]),
            'active' => $documents->count(['filters' => ['active' => true]]),
            'byLevel' => $byLevel,
        ];
    }
}
