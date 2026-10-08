<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Upload\Provider as UploadProvider;
use Strapi\Upload\Utils\Utils;

/** Port of server/src/services/metrics.ts. */
final class Metrics
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function getProviderName(): mixed
    {
        return $this->strapi->config()->get('plugin::upload.provider', 'local');
    }

    private function isProviderPrivate(): bool
    {
        $provider = $this->strapi->plugin('upload')->provider;

        return $provider instanceof UploadProvider && $provider->isPrivate();
    }

    /** @param array<string, mixed> $properties */
    public function trackUsage(string $event, array $properties = []): bool
    {
        $isAiAvailable = Utils::getService('aiMetadataProvider', $this->strapi)->hasProvider();

        $eventProperties = is_array($properties['eventProperties'] ?? null) ? $properties['eventProperties'] : [];
        if ($isAiAvailable === true) {
            $eventProperties['isAiMediaLibraryConfigured'] = Utils::getService('aiMetadata', $this->strapi)->isEnabled();
        }

        return $this->strapi->telemetry()->send($event, [
            ...$properties,
            'eventProperties' => $eventProperties,
        ]);
    }

    public function sendUploadPluginMetrics(): void
    {
        $uploadProvider = $this->getProviderName();
        $privateProvider = $this->isProviderPrivate();

        $this->trackUsage('didInitializePluginUpload', [
            'groupProperties' => [
                'uploadProvider' => $uploadProvider,
                'privateProvider' => $privateProvider,
            ],
        ]);
    }
}
