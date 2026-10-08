<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\Core\Strapi;

/** Port of server/src/services/content-structure.ts. */
final class ContentStructure
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private static function isInternalUid(string $uid): bool
    {
        return str_starts_with($uid, 'admin::') || str_starts_with($uid, 'strapi::');
    }

    private function isContentTypeVisible(\Strapi\Types\Schema\Schema $model): bool
    {
        return ($model->pluginOptions['content-manager']['visible'] ?? true) === true;
    }

    /**
     * Whether a content type may appear in the folder nav: a known, user-facing type
     * that is neither internal nor hidden via pluginOptions.
     */
    private function isFileableContentType(string $uid): bool
    {
        $schema = $this->strapi->contentTypes()[$uid] ?? null;

        if ($schema === null) {
            return false;
        }

        if (self::isInternalUid($uid)) {
            return false;
        }

        return $this->isContentTypeVisible($schema);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function pruneNode(array $node): array
    {
        $children = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (($child['type'] ?? null) === 'contentType' && !$this->isFileableContentType((string) ($child['uid'] ?? ''))) {
                continue;
            }
            $children[] = ($child['type'] ?? null) === 'group' ? $this->pruneNode($child) : $child;
        }

        return [...$node, 'children' => $children];
    }

    /** @return array{collectionTypes: list<array<string, mixed>>, singleTypes: list<array<string, mixed>>}|null */
    public function getContentStructure(): ?array
    {
        $coreContentStructure = $this->strapi->get('content-structure');

        $cleaned = $coreContentStructure->getCleanedFile();

        if ($cleaned === null) {
            return null;
        }

        $resolved = $coreContentStructure->resolve();

        return [
            'collectionTypes' => array_values(array_map(fn (array $node): array => $this->pruneNode($node), $resolved['collectionTypes'] ?? [])),
            'singleTypes' => array_values(array_map(fn (array $node): array => $this->pruneNode($node), $resolved['singleTypes'] ?? [])),
        ];
    }
}
