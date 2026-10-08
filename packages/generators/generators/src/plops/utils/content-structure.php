<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Utils;

/**
 * Port of src/plops/utils/content-structure.ts: reading `src/content-structure/groups.json` and
 * staging the assignment of a generated content type to a folder.
 *
 * The file is decoded to objects (`stdClass`) so that whatever the generator does not touch,
 * empty objects and malformed entries included, is written back unchanged.
 *
 * @phpstan-type FolderSelection array{targetGroupId: string}|array{newFolderName: string}
 * @phpstan-type FolderChoice array{value: string, name: string}
 * @phpstan-type ContentStructureReadResult array{status: 'ok', file: \stdClass}|array{status: 'invalid'}|array{status: 'absent'}
 */
final class ContentStructure
{
    public static function getContentStructureFilePath(string $destBasePath): string
    {
        return $destBasePath . DIRECTORY_SEPARATOR . 'content-structure' . DIRECTORY_SEPARATOR . 'groups.json';
    }

    private static function isRecord(mixed $value): bool
    {
        return $value instanceof \stdClass;
    }

    /** @return ContentStructureReadResult */
    public static function readContentStructureFile(string $destBasePath): array
    {
        $filePath = self::getContentStructureFilePath($destBasePath);

        if (!file_exists($filePath)) {
            return ['status' => 'absent'];
        }

        try {
            $parsed = json_decode((string) @file_get_contents($filePath), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['status' => 'invalid'];
        }

        if (!$parsed instanceof \stdClass || ($parsed->version ?? null) !== 1 || !self::isRecord($parsed->sections ?? null)) {
            return ['status' => 'invalid'];
        }

        return ['status' => 'ok', 'file' => $parsed];
    }

    /** @return 'collectionTypes'|'singleTypes' */
    public static function sectionKeyForKind(string $kind): string
    {
        return $kind === 'singleType' ? 'singleTypes' : 'collectionTypes';
    }

    private static function isWellFormedGroup(mixed $group): bool
    {
        if (!$group instanceof \stdClass) {
            return false;
        }

        $id = $group->id ?? null;
        if (!is_string($id) || $id === '') {
            return false;
        }

        $name = $group->name ?? null;
        if (!is_string($name) || trim($name) === '') {
            return false;
        }

        if (!property_exists($group, 'parent') || ($group->parent !== null && !is_string($group->parent))) {
            return false;
        }

        return is_array($group->children ?? null);
    }

    /** @return list<mixed>|null null when the section is malformed */
    public static function getSectionGroups(\stdClass $file, string $sectionKey): ?array
    {
        $section = $file->sections->{$sectionKey} ?? null;

        if ($section === null) {
            return [];
        }

        if (!self::isRecord($section) || !is_array($section->groups ?? null)) {
            return null;
        }

        return array_values($section->groups);
    }

    private static function isGroupChild(mixed $child): bool
    {
        return $child instanceof \stdClass && ($child->type ?? null) === 'group' && is_string($child->id ?? null);
    }

    /**
     * A group is an effective root when it has no parent, or its parent id points
     * at a group that no longer exists.
     *
     * @param array<string, true> $knownIds
     */
    private static function isEffectivelyRoot(\stdClass $group, array $knownIds): bool
    {
        return $group->parent === null || !isset($knownIds[$group->parent]);
    }

    /**
     * @param list<mixed> $groups
     * @return list<\stdClass>
     */
    private static function wellFormed(array $groups): array
    {
        return array_values(array_filter($groups, self::isWellFormedGroup(...)));
    }

    /**
     * @param list<mixed> $groups
     * @return list<FolderChoice>
     */
    public static function listFolderChoices(array $groups): array
    {
        $wellFormed = self::wellFormed($groups);

        $choices = [];
        $seen = [];

        /** @var array<string, \stdClass> $byId */
        $byId = [];
        foreach ($wellFormed as $group) {
            if (isset($byId[$group->id])) {
                continue;
            }
            $byId[$group->id] = $group;
        }

        $knownIds = array_fill_keys(array_keys($byId), true);

        $childGroupsOf = static function (\stdClass $parent) use ($byId, $wellFormed): array {
            $listed = [];
            foreach ($parent->children as $child) {
                if (!self::isGroupChild($child)) {
                    continue;
                }
                $group = $byId[$child->id] ?? null;
                if ($group !== null && $group->parent === $parent->id) {
                    $listed[] = $group;
                }
            }

            $unlisted = array_filter($wellFormed, static fn (\stdClass $group): bool => $group->parent === $parent->id && !in_array($group, $listed, true));

            return [...$listed, ...array_values($unlisted)];
        };

        $visit = static function (?\stdClass $parent, array $path) use (&$visit, &$choices, &$seen, $childGroupsOf, $wellFormed, $knownIds): void {
            $children = $parent !== null
                ? $childGroupsOf($parent)
                : array_values(array_filter($wellFormed, static fn (\stdClass $group): bool => self::isEffectivelyRoot($group, $knownIds)));

            foreach ($children as $group) {
                if (isset($seen[$group->id])) {
                    continue;
                }

                $seen[$group->id] = true;

                $groupPath = [...$path, $group->name];
                $choices[] = ['name' => implode(' / ', $groupPath), 'value' => $group->id];

                $visit($group, $groupPath);
            }
        };

        $visit(null, []);

        return $choices;
    }

    /**
     * Groups whose parent id does not exist are considered root level entries.
     *
     * @param list<mixed> $groups
     */
    public static function findRootFolderByName(array $groups, string $name): ?\stdClass
    {
        $target = strtolower(trim($name));

        if ($target === '') {
            return null;
        }

        $knownIds = [];
        foreach (self::wellFormed($groups) as $group) {
            $knownIds[$group->id] = true;
        }

        foreach ($groups as $group) {
            if (!self::isWellFormedGroup($group) || !$group instanceof \stdClass) {
                continue;
            }

            if (!self::isEffectivelyRoot($group, $knownIds)) {
                continue;
            }

            if (strtolower(trim($group->name)) === $target) {
                return $group;
            }
        }

        return null;
    }

    public static function generateFolderId(): string
    {
        return 'grp_' . substr(bin2hex(random_bytes(16)), 0, 24);
    }

    private static function createEmptyContentStructureFile(): \stdClass
    {
        return (object) [
            'version' => 1,
            'sections' => (object) [
                'collectionTypes' => (object) ['groups' => []],
                'singleTypes' => (object) ['groups' => []],
            ],
        ];
    }

    /** @return \stdClass|null the section (its `groups` is the list to edit), null when malformed */
    private static function ensureSection(\stdClass $file, string $sectionKey): ?\stdClass
    {
        $section = $file->sections->{$sectionKey} ?? null;

        if ($section === null) {
            $file->sections->{$sectionKey} = (object) ['groups' => []];

            return $file->sections->{$sectionKey};
        }

        if (!self::isRecord($section) || !is_array($section->groups ?? null)) {
            return null;
        }

        return $section;
    }

    /** @param FolderSelection $folder */
    private static function resolveTargetGroup(\stdClass $section, array $folder): \stdClass
    {
        /** @var list<mixed> $groups */
        $groups = $section->groups;

        if (array_key_exists('targetGroupId', $folder)) {
            foreach ($groups as $group) {
                if (self::isWellFormedGroup($group) && $group instanceof \stdClass && $group->id === $folder['targetGroupId']) {
                    return $group;
                }
            }

            throw new \RuntimeException("No usable folder with id \"{$folder['targetGroupId']}\" exists in groups.json.");
        }

        $name = is_string($folder['newFolderName'] ?? null) ? trim($folder['newFolderName']) : '';

        if ($name === '') {
            throw new \RuntimeException('The new folder name must be a non-empty string.');
        }

        $existingRootFolder = self::findRootFolderByName($groups, $name);

        if ($existingRootFolder !== null) {
            return $existingRootFolder;
        }

        $created = (object) [
            'id' => self::generateFolderId(),
            'children' => [],
            'parent' => null,
            'name' => $name,
        ];

        $section->groups[] = $created;

        return $created;
    }

    /** @param list<mixed> $groups */
    private static function removeContentTypeFromSection(array $groups, string $uid): void
    {
        foreach ($groups as $group) {
            if (!self::isWellFormedGroup($group) || !$group instanceof \stdClass) {
                continue;
            }

            $group->children = array_values(array_filter(
                $group->children,
                static fn (mixed $child): bool => !$child instanceof \stdClass || !(($child->type ?? null) === 'contentType' && ($child->uid ?? null) === $uid),
            ));
        }
    }

    /**
     * Validates the folder selection and stages the assignment of a content type in
     * `<destBasePath>/content-structure/groups.json`, creating the file (or a new root
     * folder) in memory when needed.
     *
     * @param array{folder: FolderSelection, destBasePath: string, kind: string, uid: string} $options
     * @return \Closure(): void A commit function that writes the staged result to disk.
     */
    public static function planContentTypeToFolder(array $options): \Closure
    {
        ['destBasePath' => $destBasePath, 'kind' => $kind, 'uid' => $uid, 'folder' => $folder] = $options;
        $filePath = self::getContentStructureFilePath($destBasePath);

        $read = self::readContentStructureFile($destBasePath);

        if ($read['status'] === 'invalid') {
            throw new \RuntimeException("Cannot assign \"{$uid}\" to a folder: {$filePath} exists but is not a valid content-structure file.");
        }

        $file = $read['status'] === 'ok' ? $read['file'] : self::createEmptyContentStructureFile();

        $sectionKey = self::sectionKeyForKind($kind);
        $section = self::ensureSection($file, $sectionKey);

        if ($section === null) {
            throw new \RuntimeException("Cannot assign \"{$uid}\" to a folder: the \"{$sectionKey}\" section of {$filePath} is malformed.");
        }

        $targetGroup = self::resolveTargetGroup($section, $folder);

        self::removeContentTypeFromSection(array_values($section->groups), $uid);
        $targetGroup->children[] = (object) ['type' => 'contentType', 'uid' => $uid];

        return static function () use ($filePath, $file): void {
            Files::outputJSON($filePath, $file);
        };
    }
}
