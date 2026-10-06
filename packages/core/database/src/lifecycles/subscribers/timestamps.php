<?php

declare(strict_types=1);

namespace Strapi\Database\Lifecycles\Subscribers;

use Strapi\Database\Lifecycles\Event;

/** Port of packages/core/database/src/lifecycles/subscribers/timestamps.ts. */
final class Timestamps
{
    /** @return array<string, callable(Event): void> the subscriber map */
    public static function subscriber(): array
    {
        return [
            'beforeCreate' => static function (Event $event): void {
                $now = new \DateTimeImmutable();
                $data = $event->params['data'] ?? [];
                if (is_array($data)) {
                    $data += ['createdAt' => $now, 'updatedAt' => $now];
                    $event->params['data'] = $data;
                }
            },
            'beforeCreateMany' => static function (Event $event): void {
                $now = new \DateTimeImmutable();
                $data = $event->params['data'] ?? null;
                if (is_array($data)) {
                    foreach ($data as $i => $datum) {
                        if (is_array($datum)) {
                            $data[$i] = $datum + ['createdAt' => $now, 'updatedAt' => $now];
                        }
                    }
                    $event->params['data'] = $data;
                }
            },
            'beforeUpdate' => static function (Event $event): void {
                $data = $event->params['data'] ?? [];
                if (is_array($data)) {
                    $data['updatedAt'] = new \DateTimeImmutable();
                    $event->params['data'] = $data;
                }
            },
            'beforeUpdateMany' => static function (Event $event): void {
                $data = $event->params['data'] ?? null;
                if (is_array($data) && array_is_list($data)) {
                    $now = new \DateTimeImmutable();
                    foreach ($data as $i => $datum) {
                        if (is_array($datum)) {
                            $data[$i]['updatedAt'] = $now;
                        }
                    }
                    $event->params['data'] = $data;
                } elseif (is_array($data)) {
                    $data['updatedAt'] = new \DateTimeImmutable();
                    $event->params['data'] = $data;
                }
            },
        ];
    }
}
