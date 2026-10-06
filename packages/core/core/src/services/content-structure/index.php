<?php

declare(strict_types=1);

namespace Strapi\Core\Services\ContentStructure;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/content-structure/index.ts.
 *
 * STUB (TODO): the folder-groups feature (`src/content-structure/groups.json` validation and the
 * admin endpoints) is not ported. `getCleanedFile()` reads the file when present and returns it
 * as is without validation; `countGroups()` counts its top-level `groups`.
 */
final class ContentStructure
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createContentStructureService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    private function filePath(): string
    {
        return $this->strapi->dirs()->src . '/content-structure/groups.json';
    }

    /** @return array<string, mixed>|null */
    public function getCleanedFile(): ?array
    {
        $file = $this->filePath();
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function countGroups(): int
    {
        $file = $this->getCleanedFile();
        $groups = $file['groups'] ?? [];

        return is_array($groups) ? count($groups) : 0;
    }
}
