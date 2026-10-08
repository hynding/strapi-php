<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\Relations;

/** Port of server/src/services/metrics.ts. */
final class Metrics
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param array<string, mixed> $contentType
     * @param array<string, mixed> $configuration
     */
    public function sendDidConfigureListView(array $contentType, array $configuration): void
    {
        $list = is_array($configuration['layouts']['list'] ?? null) ? $configuration['layouts']['list'] : [];
        $displayedFields = count($list);
        $relationalFields = Relations::getRelationalFields($contentType);
        $displayedRelationalFields = count(array_intersect($relationalFields, $list));

        $data = [
            'eventProperties' => ['containsRelationalFields' => $displayedRelationalFields > 0],
        ];

        if ($data['eventProperties']['containsRelationalFields']) {
            $data['eventProperties']['displayedFields'] = $displayedFields;
            $data['eventProperties']['displayedRelationalFields'] = $displayedRelationalFields;
        }

        try {
            $this->strapi->get('telemetry')->send('didConfigureListView', $data);
        } catch (\Throwable) {
        }
    }
}
