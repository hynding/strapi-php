<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/content-structure.ts (`createContentStructureService`).
 *
 * This service handles the CTB orchestration of folder-group WRITES. It handles:
 * - Context-aware validation that requires the content-type registry
 * - Context-aware pruning of references invalidated by CTB transaction.
 * - Persistence to filesystem via the core content-structure service
 *   (`strapi.get('content-structure')`: `getCleanedFile()` and `write()`).
 * It assumes that non-context-aware validation has already been performed.
 *
 * Upstream's `Map<uid, kind>` is an `array<uid, kind>` and its `Set<uid>` a `list<uid>`.
 *
 * @phpstan-type ContentStructureGroup array{parent: string|null, name: string, id: string, children: list<array<string, mixed>>}
 * @phpstan-type ContentStructureFile array{version: 1, sections: array{collectionTypes: array{groups: list<ContentStructureGroup>}, singleTypes: array{groups: list<ContentStructureGroup>}}}
 */
final class ContentStructure
{
    private const array SECTION_KEYS = ['collectionTypes', 'singleTypes'];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createContentStructureService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    private static function expectedKindFor(string $sectionKey): string
    {
        return $sectionKey === 'collectionTypes' ? 'collectionType' : 'singleType';
    }

    private function warn(string $message): void
    {
        $this->strapi->log()->warning("[content-structure] {$message}");
    }

    private static function isRecord(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * Formats a validated ContentStructureFile into a complete `groups.json` file.
     *
     * @return ContentStructureFile
     */
    public static function formatContentStructureObjectAsFile(mixed $structure): array
    {
        $sections = self::isRecord($structure) && self::isRecord($structure['sections'] ?? null) ? $structure['sections'] : [];

        // Supplies all record groups with expected fields, so that the reference validator
        // can access `children`/`id` unconditionally.
        $coerceGroup = static fn (array $raw): array => [
            'parent' => is_string($raw['parent'] ?? null) ? $raw['parent'] : null,
            'name' => is_string($raw['name'] ?? null) ? $raw['name'] : '',
            'id' => is_string($raw['id'] ?? null) ? $raw['id'] : '',
            'children' => is_array($raw['children'] ?? null) && array_is_list($raw['children']) ? $raw['children'] : [],
        ];

        $coerceSection = static function (string $key) use ($sections, $coerceGroup): array {
            $raw = $sections[$key] ?? null;
            $groups = self::isRecord($raw) && is_array($raw['groups'] ?? null) && array_is_list($raw['groups']) ? $raw['groups'] : [];

            return ['groups' => array_values(array_map($coerceGroup, array_filter($groups, self::isRecord(...))))];
        };

        /** @var ContentStructureFile */
        return [
            'version' => 1,
            'sections' => [
                'collectionTypes' => $coerceSection('collectionTypes'),
                'singleTypes' => $coerceSection('singleTypes'),
            ],
        ];
    }

    /**
     * Builds an effective set of content type uids and their kind by resolving the pre-transaction
     * content type registry with the list of upserted and deleted uids.
     *
     * @param array<string, string> $upsertedUids
     * @param list<string> $deletedUids
     * @return array<string, string>
     */
    private function buildEffectiveUidKindSet(array $upsertedUids, array $deletedUids): array
    {
        $effective = [];

        foreach ($this->strapi->contentTypes() as $uid => $contentType) {
            $effective[(string) $uid] = $contentType->kind === 'singleType' ? 'singleType' : 'collectionType';
        }

        foreach ($deletedUids as $uid) {
            unset($effective[$uid]);
        }

        foreach ($upsertedUids as $uid => $kind) {
            $effective[(string) $uid] = $kind;
        }

        return $effective;
    }

    /**
     * Remove references to content types that have been deleted.
     *
     * @param ContentStructureFile $file
     * @param list<string> $deletedUids
     * @return ContentStructureFile
     */
    private function pruneStructure(array $file, array $deletedUids): array
    {
        foreach (self::SECTION_KEYS as $sectionKey) {
            foreach ($file['sections'][$sectionKey]['groups'] as $i => $group) {
                $children = [];
                foreach ($group['children'] as $child) {
                    if (($child['type'] ?? null) === 'contentType' && in_array($child['uid'] ?? null, $deletedUids, true)) {
                        $this->warn(sprintf('Pruned deleted content type "%s" from group "%s"', (string) $child['uid'], $group['id']));
                        continue;
                    }
                    $children[] = $child;
                }
                $file['sections'][$sectionKey]['groups'][$i]['children'] = $children;
            }
        }

        return $file;
    }

    /**
     * Validates all references to content types in the provided content structure.
     * Throws an ApplicationError naming every offending uid + rule.
     *
     * @param array<string, string> $contentTypeKinds
     */
    public function validateContentTypeUidReferences(mixed $structure, array $contentTypeKinds): void
    {
        $file = self::formatContentStructureObjectAsFile($structure);
        $violations = [];

        foreach (self::SECTION_KEYS as $sectionKey) {
            $expectedKind = self::expectedKindFor($sectionKey);

            foreach ($file['sections'][$sectionKey]['groups'] as $group) {
                foreach ($group['children'] as $child) {
                    if (!is_array($child) || ($child['type'] ?? null) !== 'contentType') {
                        continue;
                    }

                    $uid = is_scalar($child['uid'] ?? null) ? (string) $child['uid'] : 'undefined';

                    // Referenced content type exists (in the effective set).
                    if (!array_key_exists($uid, $contentTypeKinds)) {
                        $violations[] = "Content type \"{$uid}\" in group \"{$group['id']}\" does not exist in section \"{$sectionKey}\"";
                        continue;
                    }

                    // Kind matches section.
                    if ($contentTypeKinds[$uid] !== $expectedKind) {
                        $violations[] = "Content type \"{$uid}\" in group \"{$group['id']}\" is a {$contentTypeKinds[$uid]} and cannot be placed in section \"{$sectionKey}\"";
                    }
                }
            }
        }

        if ($violations !== []) {
            throw new ApplicationError("Invalid content structure:\n- " . implode("\n- ", $violations), [
                'errors' => array_map(static fn (string $message): array => [
                    'name' => 'ApplicationError',
                    'path' => [],
                    'message' => $message,
                ], $violations),
            ]);
        }
    }

    /**
     * @param array{incomingStructure?: mixed, upsertedUids: array<string, string>, deletedUids: list<string>} $input
     */
    public function validateFromUpdate(array $input): void
    {
        $incomingStructure = $input['incomingStructure'] ?? null;
        if ($incomingStructure === null) {
            return;
        }

        $effectiveKinds = $this->buildEffectiveUidKindSet($input['upsertedUids'], $input['deletedUids']);
        $pruned = $this->pruneStructure(self::formatContentStructureObjectAsFile($incomingStructure), $input['deletedUids']);

        $this->validateContentTypeUidReferences($pruned, $effectiveKinds);
    }

    /**
     * @param array{incomingStructure?: mixed, deletedUids: list<string>} $input
     */
    public function commitFromUpdate(array $input): bool
    {
        $incomingStructure = $input['incomingStructure'] ?? null;
        $deletedUids = $input['deletedUids'];
        $core = $this->strapi->get('content-structure');

        if ($incomingStructure !== null) {
            $pruned = $this->pruneStructure(self::formatContentStructureObjectAsFile($incomingStructure), $deletedUids);

            $core->write($pruned);

            return true;
        }

        if ($deletedUids !== []) {
            $current = $core->getCleanedFile();

            if ($current === null) {
                return false;
            }

            $pruned = $this->pruneStructure(self::formatContentStructureObjectAsFile($current), $deletedUids);

            $core->write($pruned);

            return true;
        }

        return false;
    }
}
