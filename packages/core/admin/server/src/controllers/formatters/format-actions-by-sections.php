<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers\Formatters;

/** Port of server/src/controllers/formatters/format-actions-by-sections.ts. */
final class FormatActionsBySections
{
    /**
     * Transform an array of actions to a more nested format: `{ contentTypes, plugins, settings }`.
     *
     * @param list<array<string, mixed>> $actions
     * @return array<string, list<array<string, mixed>>>
     */
    public static function formatActionsBySections(array $actions): array
    {
        $result = [];

        foreach ($actions as $p) {
            $checkboxItem = [
                'displayName' => $p['displayName'] ?? null,
                'action' => $p['actionId'] ?? null,
            ];

            $section = $p['section'] ?? null;
            switch ($section) {
                case 'contentTypes':
                    $checkboxItem['subjects'] = $p['subjects'] ?? null;
                    break;
                case 'plugins':
                    $checkboxItem['subCategory'] = $p['subCategory'] ?? null;
                    $checkboxItem['plugin'] = 'plugin::' . (string) ($p['pluginName'] ?? 'undefined');
                    break;
                case 'settings':
                    $checkboxItem['category'] = $p['category'] ?? null;
                    $checkboxItem['subCategory'] = $p['subCategory'] ?? null;
                    break;
                default:
                    throw new \RuntimeException('Unknown section ' . (is_scalar($section) ? (string) $section : 'undefined'));
            }

            $result[$section] ??= [];
            $result[$section][] = $checkboxItem;
        }

        return $result;
    }
}
