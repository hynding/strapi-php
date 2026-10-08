<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers\Utils;

use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Strapi;

/** Port of server/src/controllers/utils/metadata.ts. */
final class Metadata
{
    /**
     * Format a document with metadata. Making sure the metadata response is
     * correctly sanitized for the current user
     *
     * @param PermissionChecker $permissionChecker
     * @param array<string, mixed>|null $document
     * @param array{availableLocales?: bool, availableStatus?: bool} $opts
     * @return array{data: mixed, meta: array{availableLocales: list<mixed>, availableStatus: list<mixed>}}
     */
    public static function formatDocumentWithMetadata(
        Strapi $strapi,
        object $permissionChecker,
        string $uid,
        ?array $document,
        array $opts = [],
    ): array {
        $documentMetadata = Utils::getService($strapi, 'document-metadata');

        $serviceOutput = $documentMetadata->formatDocumentWithMetadata($uid, $document, $opts);

        $availableLocales = array_map(
            static fn (mixed $localeDocument): mixed => $permissionChecker->sanitizeOutput($localeDocument),
            $serviceOutput['meta']['availableLocales'],
        );

        $availableStatus = array_map(
            static fn (mixed $statusDocument): mixed => $permissionChecker->sanitizeOutput($statusDocument),
            $serviceOutput['meta']['availableStatus'],
        );

        return [
            ...$serviceOutput,
            'meta' => [
                'availableLocales' => array_values($availableLocales),
                'availableStatus' => array_values($availableStatus),
            ],
        ];
    }
}
