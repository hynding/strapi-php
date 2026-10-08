<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Strapi;

/** Port of server/src/services/content-types.ts. */
final class ContentTypes
{
    private readonly Configuration $configurationService;

    public function __construct(private readonly Strapi $strapi)
    {
        $this->configurationService = new Configuration(
            $strapi,
            'content_types',
            static function () use ($strapi): array {
                $dataMapper = Utils::getService($strapi, 'data-mapper');

                return array_map(static fn ($contentType): array => $dataMapper->toContentManagerModel($contentType), $strapi->contentTypes());
            },
        );
    }

    /** @return list<array<string, mixed>> */
    public function findAllContentTypes(): array
    {
        $dataMapper = Utils::getService($this->strapi, 'data-mapper');

        return array_values(array_map(static fn ($contentType): array => $dataMapper->toContentManagerModel($contentType), $this->strapi->contentTypes()));
    }

    /** @return array<string, mixed>|null */
    public function findContentType(string $uid): ?array
    {
        $dataMapper = Utils::getService($this->strapi, 'data-mapper');

        $contentType = $this->strapi->contentTypes()[$uid] ?? null;

        return $contentType === null ? null : $dataMapper->toContentManagerModel($contentType);
    }

    /** @return list<array<string, mixed>> */
    public function findDisplayedContentTypes(): array
    {
        return array_values(array_filter(
            $this->findAllContentTypes(),
            static fn (array $contentType): bool => ($contentType['isDisplayed'] ?? null) === true,
        ));
    }

    /** @return list<array<string, mixed>> */
    public function findContentTypesByKind(?string $kind): array
    {
        if ($kind === null || $kind === '') {
            return $this->findAllContentTypes();
        }

        return array_values(array_filter(
            $this->findAllContentTypes(),
            \Strapi\Utils\ContentTypes::isKind($kind),
        ));
    }

    /**
     * @param array<string, mixed> $contentType
     * @return array<string, mixed>
     */
    public function findConfiguration(array $contentType): array
    {
        $configuration = $this->configurationService->getConfiguration((string) $contentType['uid']);

        return [
            'uid' => $contentType['uid'],
            ...$configuration,
        ];
    }

    /**
     * @param array<string, mixed> $contentType
     * @param array<string, mixed> $newConfiguration
     * @return array<string, mixed>
     */
    public function updateConfiguration(array $contentType, array $newConfiguration): array
    {
        $this->configurationService->setConfiguration((string) $contentType['uid'], $newConfiguration);

        return $this->findConfiguration($contentType);
    }

    /**
     * @param array<string, mixed> $contentType
     * @return array<string, array<string, mixed>>
     */
    public function findComponentsConfigurations(array $contentType): array
    {
        // delegate to componentService
        return Utils::getService($this->strapi, 'components')->findComponentsConfigurations($contentType);
    }

    public function syncConfigurations(): void
    {
        $this->configurationService->syncConfigurations();
    }
}
