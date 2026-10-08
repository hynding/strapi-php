<?php

declare(strict_types=1);

namespace Strapi\Core\Services\ContentStructure;

use Strapi\Core\Services\ContentStructure\Utils\IsGroupExpressionValid;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/content-structure/index.ts: the content-type folder
 * groups (`src/content-structure/groups.json`).
 *
 * - `read()` reads the file with minimal sanitization;
 * - `getCleanedFile()` yields an extremely tolerant version of it (invalid references are
 *   repaired or ignored, with a warning for each repair/omission);
 * - `resolve()` returns the groups as nested trees;
 * - `write()` persists a new file (no validation) and invalidates the cache;
 * - `validate()` strictly validates a value against the file schema.
 *
 * The cache lives as long as the instance, like upstream's (a save restarts the server).
 *
 * @phpstan-type ContentStructureChild array{type: 'contentType', uid: string}|array{type: 'group', id: string}
 * @phpstan-type ContentStructureGroup array{children: list<ContentStructureChild>, parent: string|null, name: string, id: string}
 * @phpstan-type ContentStructureSection array{groups: list<ContentStructureGroup>}
 * @phpstan-type ContentStructureFile array{version: 1, sections: array{collectionTypes: ContentStructureSection, singleTypes: ContentStructureSection}}
 * @phpstan-type ResolvedGroupNode array{children: list<mixed>, name: string, type: 'group', id: string}
 * @phpstan-type ResolvedContentStructure array{collectionTypes: list<ResolvedGroupNode>, singleTypes: list<ResolvedGroupNode>}
 */
final class ContentStructure
{
    public const string CONTENT_STRUCTURE_FILE_NAME = 'groups.json';

    public const int MAX_FOLDER_DEPTH = 3;

    /** @var array{cleaned: ContentStructureFile|null, resolved: ResolvedContentStructure}|null */
    private ?array $cache = null;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createContentStructureService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    private function dir(): string
    {
        // strapi.dirs.app.contentStructure / strapi.dirs.dist.contentStructure
        return $this->strapi->dirs()->src . '/content-structure';
    }

    private function warn(string $message): void
    {
        $this->strapi->log()->warning("[content-structure] {$message}");
    }

    private static function isRecord(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** @return ContentStructureSection */
    private function cleanSection(mixed $rawSection, string $sectionKey): array
    {
        if ($rawSection === null) {
            $rawGroups = [];
        } elseif (!self::isRecord($rawSection) || !is_array($rawSection['groups'] ?? null) || !array_is_list($rawSection['groups'])) {
            $this->warn("Section \"{$sectionKey}\" is malformed; ignoring it");
            $rawGroups = [];
        } else {
            $rawGroups = $rawSection['groups'];
        }

        /** @var array<string, int> $seenGroupIds id => index in $groups */
        $seenGroupIds = [];
        /** @var list<ContentStructureGroup> $groups */
        $groups = [];

        foreach ($rawGroups as $raw) {
            if (!IsGroupExpressionValid::isGroupExpressionValid($raw)) {
                $this->warn("Dropping a malformed group entry in section \"{$sectionKey}\"");
                continue;
            }

            if (self::jsTrim($raw['name']) === '') {
                $this->warn("Dropping group \"{$raw['id']}\" in section \"{$sectionKey}\": empty name");
                continue;
            }

            if (array_key_exists($raw['id'], $seenGroupIds)) {
                $this->warn("Dropping duplicate group id \"{$raw['id']}\" in section \"{$sectionKey}\"");
                continue;
            }

            $groups[] = [
                'children' => $raw['children'],
                'parent' => $raw['parent'],
                'name' => $raw['name'],
                'id' => $raw['id'],
            ];
            $seenGroupIds[$raw['id']] = 0;
        }

        $byId = static function (array $groups, string $id): ?array {
            foreach ($groups as $group) {
                if ($group['id'] === $id) {
                    return $group;
                }
            }

            return null;
        };

        $reparentToRoot = function (array &$groups, string $id, string $reason) use ($sectionKey): void {
            foreach ($groups as $i => $group) {
                if ($group['id'] === $id) {
                    $group['parent'] = null;
                    array_splice($groups, $i, 1);
                    $groups[] = $group;
                    break;
                }
            }

            $this->warn("Reparenting group \"{$id}\" in section \"{$sectionKey}\" to root: {$reason}");
        };

        foreach (array_column($groups, 'id') as $id) {
            $group = $byId($groups, $id);
            if ($group !== null && $group['parent'] !== null && !array_key_exists($group['parent'], $seenGroupIds)) {
                $reparentToRoot($groups, $id, "parent \"{$group['parent']}\" does not exist");
            }
        }

        foreach (array_column($groups, 'id') as $id) {
            $group = $byId($groups, $id);
            if ($group === null) {
                continue;
            }
            $chain = [$id => true];
            $current = $group;

            while ($current !== null && $current['parent'] !== null) {
                if (array_key_exists($current['parent'], $chain)) {
                    $reparentToRoot($groups, $id, 'its parent chain contains a cycle');
                    break;
                }

                $chain[$current['parent']] = true;
                $current = $byId($groups, $current['parent']);
            }
        }

        foreach (array_column($groups, 'id') as $id) {
            $current = $byId($groups, $id);
            $depth = 1;

            while ($current !== null && $current['parent'] !== null) {
                $current = $byId($groups, $current['parent']);
                $depth++;
            }

            if ($depth > self::MAX_FOLDER_DEPTH) {
                $reparentToRoot($groups, $id, 'it exceeds the maximum nesting depth of ' . self::MAX_FOLDER_DEPTH);
            }
        }

        $expectedKind = $sectionKey === 'collectionTypes' ? 'collectionType' : 'singleType';
        $seenGroupChildren = [];
        $seenUids = [];
        $contentTypes = $this->strapi->contentTypes();

        foreach ($groups as $gi => $group) {
            $cleanedChildren = [];

            foreach ($group['children'] as $entry) {
                if (!self::isRecord($entry)) {
                    $this->warn("Dropping a malformed child entry of group \"{$group['id']}\"");
                    continue;
                }

                $type = $entry['type'] ?? null;

                if ($type === 'contentType') {
                    $uid = $entry['uid'] ?? null;

                    if (!is_string($uid)) {
                        $this->warn("Dropping a contentType child of group \"{$group['id']}\": invalid uid");
                        continue;
                    }

                    $schema = $contentTypes[$uid] ?? null;

                    if ($schema === null) {
                        $this->warn("Dropping unknown content type \"{$uid}\" from group \"{$group['id']}\"");
                        continue;
                    }

                    // kind is optional on schemas; absent means collectionType.
                    $kind = $schema->kind ?? 'collectionType';

                    if ($kind !== $expectedKind) {
                        $this->warn("Dropping content type \"{$uid}\" from group \"{$group['id']}\": its kind \"{$kind}\" does not belong in section \"{$sectionKey}\"");
                        continue;
                    }

                    if (array_key_exists($uid, $seenUids)) {
                        $this->warn("Dropping duplicate reference to content type \"{$uid}\" from group \"{$group['id']}\"");
                        continue;
                    }

                    $cleanedChildren[] = ['type' => 'contentType', 'uid' => $uid];
                    $seenUids[$uid] = true;
                    continue;
                }

                if ($type === 'group') {
                    $id = $entry['id'] ?? null;

                    if (!is_string($id)) {
                        $this->warn("Dropping a group child of group \"{$group['id']}\": invalid id");
                        continue;
                    }

                    $target = $byId($groups, $id);

                    if ($target === null || $target['parent'] !== $group['id']) {
                        $this->warn("Dropping group child \"{$id}\" from group \"{$group['id']}\": inconsistent reference");
                        continue;
                    }

                    if (array_key_exists($id, $seenGroupChildren)) {
                        $this->warn("Dropping duplicate group child \"{$id}\" from group \"{$group['id']}\"");
                        continue;
                    }

                    $cleanedChildren[] = ['type' => 'group', 'id' => $id];
                    $seenGroupChildren[$id] = true;
                    continue;
                }

                $this->warn("Dropping a child entry of group \"{$group['id']}\" with an unknown type");
            }

            $groups[$gi]['children'] = $cleanedChildren;
        }

        foreach ($groups as $group) {
            if ($group['parent'] !== null && !array_key_exists($group['id'], $seenGroupChildren)) {
                foreach ($groups as $pi => $parent) {
                    if ($parent['id'] === $group['parent']) {
                        $groups[$pi]['children'][] = ['type' => 'group', 'id' => $group['id']];
                        break;
                    }
                }
                $seenGroupChildren[$group['id']] = true;

                $this->warn("Group \"{$group['id']}\" was missing from the children of its parent \"{$group['parent']}\"; appended it");
            }
        }

        /** @var ContentStructureSection */
        return ['groups' => array_values($groups)];
    }

    /**
     * @param ContentStructureSection $section
     * @return list<ResolvedGroupNode>
     */
    private static function resolveSection(array $section): array
    {
        $byId = [];
        foreach ($section['groups'] as $group) {
            $byId[$group['id']] = $group;
        }

        $toNode = static function (array $group) use (&$toNode, $byId): array {
            $cleanedChildren = array_map(static function (array $entry) use (&$toNode, $byId): array {
                if ($entry['type'] === 'contentType') {
                    return ['type' => 'contentType', 'uid' => $entry['uid']];
                }

                return $toNode($byId[$entry['id']]);
            }, $group['children']);

            return [
                'children' => array_values($cleanedChildren),
                'name' => $group['name'],
                'type' => 'group',
                'id' => $group['id'],
            ];
        };

        $roots = array_filter($section['groups'], static fn (array $group): bool => $group['parent'] === null);

        /** @var list<ResolvedGroupNode> */
        return array_values(array_map($toNode, $roots));
    }

    /**
     * Reads the contents of groups.json directly with minimal sanitization.
     *
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        $filePath = $this->dir() . '/' . self::CONTENT_STRUCTURE_FILE_NAME;

        if (!file_exists($filePath)) {
            return null;
        }

        try {
            $parsed = json_decode((string) file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            $this->strapi->log()->error("[content-structure] Could not parse {$filePath} ({$error->getMessage()}); continuing without content-type folders");

            return null;
        }

        if (!self::isRecord($parsed) || !self::isRecord($parsed['sections'] ?? null)) {
            $this->strapi->log()->error("[content-structure] {$filePath} is not an object with a \"sections\" property; continuing without content-type folders");

            return null;
        }

        if (($parsed['version'] ?? null) !== 1) {
            $version = is_scalar($parsed['version'] ?? null) ? (string) $parsed['version'] : 'undefined';
            $this->strapi->log()->error("[content-structure] Unknown version \"{$version}\" in {$filePath} (expected 1); continuing without content-type folders");

            return null;
        }

        return $parsed;
    }

    /** @return array{cleaned: ContentStructureFile|null, resolved: ResolvedContentStructure} */
    private function load(): array
    {
        if ($this->cache === null) {
            $file = $this->read();

            if ($file === null) {
                $this->cache = ['cleaned' => null, 'resolved' => ['collectionTypes' => [], 'singleTypes' => []]];
            } else {
                $cleaned = [
                    'version' => 1,
                    'sections' => [
                        'collectionTypes' => $this->cleanSection($file['sections']['collectionTypes'] ?? null, 'collectionTypes'),
                        'singleTypes' => $this->cleanSection($file['sections']['singleTypes'] ?? null, 'singleTypes'),
                    ],
                ];

                $this->cache = [
                    'cleaned' => $cleaned,
                    'resolved' => [
                        'collectionTypes' => self::resolveSection($cleaned['sections']['collectionTypes']),
                        'singleTypes' => self::resolveSection($cleaned['sections']['singleTypes']),
                    ],
                ];
            }
        }

        return $this->cache;
    }

    /** Invalidates the cached parsed groups.json file. */
    public function invalidate(): void
    {
        $this->cache = null;
    }

    /** @return ContentStructureFile|null */
    public function getCleanedFile(): ?array
    {
        return $this->load()['cleaned'];
    }

    /** @return ResolvedContentStructure */
    public function resolve(): array
    {
        return $this->load()['resolved'];
    }

    /**
     * Writes a new config and invalidates the cache. Does NOT perform validation - this is left
     * to the caller. Written like `fse.writeJSON(file, structure, { spaces: 2 })`.
     *
     * @param array<string, mixed> $structure
     */
    public function write(array $structure): void
    {
        $dir = $this->dir();

        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("EACCES: permission denied, mkdir '{$dir}'");
        }

        $json = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        // JSON.stringify(value, null, 2): two-space indentation
        $json = (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json);

        if (@file_put_contents($dir . '/' . self::CONTENT_STRUCTURE_FILE_NAME, $json . "\n") === false) {
            throw new \RuntimeException("EACCES: permission denied, open '{$dir}/" . self::CONTENT_STRUCTURE_FILE_NAME . "'");
        }

        $this->invalidate();
    }

    /**
     * Strictly validates an unknown value against the canonical groups.json schema; throws if invalid.
     *
     * @return array<string, mixed>
     */
    public function validate(mixed $value): array
    {
        /** @var array<string, mixed> */
        return Validation::contentStructureFileSchema()->parse($value);
    }

    /** Total number of groups across both sections of the cleaned file. */
    public function countGroups(): int
    {
        $cleaned = $this->load()['cleaned'];

        if ($cleaned === null) {
            return 0;
        }

        return count($cleaned['sections']['collectionTypes']['groups']) + count($cleaned['sections']['singleTypes']['groups']);
    }

    /** `String.prototype.trim()`. */
    private static function jsTrim(string $value): string
    {
        return (string) preg_replace('/^[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+|[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $value);
    }
}
