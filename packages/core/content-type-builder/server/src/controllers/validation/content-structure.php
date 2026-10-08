<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers\Validation;

use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ParsePayload;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of server/src/controllers/validation/content-structure.ts: the `groups.json` file schema
 * (shape, then the context-unaware graph rules).
 */
final class ContentStructure
{
    private const string CONTROL_CHARS_REGEX = '/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u';
    private const string ANSI_ESCAPE_REGEX = '/\x1B\[[0-9;]*[A-Za-z]/';

    private const int GROUP_NAME_MAX_LENGTH = 255;

    private const int MAX_FOLDER_DEPTH = 3;

    private static function groupIdSchema(): ZodType
    {
        return z::string()->min(1, 'Group id must not be empty');
    }

    private static function groupNameSchema(): ZodType
    {
        return z::string()
            ->min(1, 'Group name must not be empty')
            ->max(self::GROUP_NAME_MAX_LENGTH, 'Group name must be at most ' . self::GROUP_NAME_MAX_LENGTH . ' characters')
            ->refine(static fn (mixed $value): bool => self::jsTrim((string) $value) !== '', 'Group name must not be empty')
            ->refine(static fn (mixed $value): bool => $value === self::jsTrim((string) $value), 'Group name must not have leading or trailing whitespace')
            ->refine(static fn (mixed $value): bool => preg_match(self::CONTROL_CHARS_REGEX, (string) $value) !== 1, 'Group name must not contain control characters')
            ->refine(static fn (mixed $value): bool => preg_match(self::ANSI_ESCAPE_REGEX, (string) $value) !== 1, 'Group name must not contain ANSI escape characters');
    }

    public static function contentStructureChildSchema(): ZodType
    {
        return z::discriminatedUnion('type', [
            z::object([
                'uid' => z::string()->regex(Common::CONTENT_TYPE_UID_REGEX, 'Invalid content type uid'),
                'type' => z::literal('contentType'),
            ]),
            z::object([
                'type' => z::literal('group'),
                'id' => self::groupIdSchema(),
            ]),
        ]);
    }

    /** Zod schema for a group record */
    public static function contentStructureGroupSchema(): ZodType
    {
        return z::object([
            'children' => z::array(self::contentStructureChildSchema()),
            'parent' => self::groupIdSchema()->nullable(),
            'name' => self::groupNameSchema(),
            'id' => self::groupIdSchema(),
        ]);
    }

    private static function contentStructureFileObjectSchema(): ZodType
    {
        $section = z::object([
            'groups' => z::array(self::contentStructureGroupSchema()),
        ]);

        return z::object([
            'version' => z::literal(1),
            'sections' => z::object([
                'collectionTypes' => $section,
                'singleTypes' => $section,
            ]),
        ]);
    }

    /**
     * File-wide group-id uniqueness across singleTypes and collectionTypes.
     *
     * @param array<string, mixed> $file
     */
    private static function uniqueGroupIdsAcrossFile(array $file, ParsePayload $ctx): void
    {
        $seen = [];

        foreach ([$file['sections']['collectionTypes'], $file['sections']['singleTypes']] as $section) {
            foreach ($section['groups'] as $group) {
                if (array_key_exists($group['id'], $seen)) {
                    $ctx->addIssue([
                        'message' => "Duplicate group id \"{$group['id']}\"",
                        'code' => 'custom',
                        'path' => ['sections'],
                    ]);
                }

                $seen[$group['id']] = true;
            }
        }
    }

    /**
     * File-internal graph resolution/validation.
     * This performs all context-UNAWARE validation of the structure. Context-aware validation is
     * performed at the CTB service layer.
     *
     * @param list<array{children: list<array<string, mixed>>, parent: string|null, name: string, id: string}> $groups
     */
    private static function validateSectionGraph(array $groups, string $sectionKey, ParsePayload $ctx): void
    {
        $path = ['sections', $sectionKey, 'groups'];
        $byId = [];
        foreach ($groups as $group) {
            $byId[$group['id']] = $group;
        }

        $report = static function (string $message) use ($ctx, $path): void {
            $ctx->addIssue(['code' => 'custom', 'path' => $path, 'message' => $message]);
        };

        // Sibling group names must be unique within the same parent (case-insensitive).
        $siblingNames = [];

        foreach ($groups as $group) {
            $name = mb_strtolower(self::jsTrim($group['name']));
            $parentKey = $group['parent'] ?? "\0null";
            $seen = $siblingNames[$parentKey] ?? [];

            if (array_key_exists($name, $seen)) {
                $where = $group['parent'] !== null && $group['parent'] !== '' ? "group \"{$group['parent']}\"" : 'the section root';
                $report("Sibling groups under {$where} in section \"{$sectionKey}\" share the name \"" . self::jsTrim($group['name']) . '"');
            }

            $seen[$name] = true;
            $siblingNames[$parentKey] = $seen;
        }

        // Parent references a group in the SAME section.
        // No cycles
        // Depth <= 3
        foreach ($groups as $group) {
            if ($group['parent'] !== null && !array_key_exists($group['parent'], $byId)) {
                $report("Group \"{$group['id']}\" references parent \"{$group['parent']}\" which is not a group in section \"{$sectionKey}\"");
                continue;
            }

            $chain = [$group['id'] => true];

            $current = $group;
            $depth = 1;
            $broken = false;

            while ($current !== null && $current['parent'] !== null) {
                if (array_key_exists($current['parent'], $chain)) {
                    $report("Group \"{$group['id']}\" is part of a parent cycle");
                    $broken = true;
                    break;
                }

                $parent = $byId[$current['parent']] ?? null;

                if ($parent === null) {
                    $broken = true;
                    break;
                }

                $chain[$current['parent']] = true;
                $current = $parent;
                $depth++;
            }

            if (!$broken && $depth > self::MAX_FOLDER_DEPTH) {
                $report("Group \"{$group['id']}\" exceeds the maximum nesting depth of " . self::MAX_FOLDER_DEPTH . " in section \"{$sectionKey}\"");
            }
        }

        // Group children reference an existing group in the same section whose parent points back at the containing group.
        // Also, each content type appears in at most ONE group.
        $groupChildCount = [];
        $seenUids = [];

        foreach ($groups as $group) {
            foreach ($group['children'] as $child) {
                if (($child['type'] ?? null) === 'group') {
                    $childId = (string) $child['id'];
                    $target = $byId[$childId] ?? null;

                    if ($target === null) {
                        $report("Group \"{$group['id']}\" has a group child \"{$childId}\" that does not exist in section \"{$sectionKey}\"");
                        continue;
                    }

                    if ($target['parent'] !== $group['id']) {
                        $report("Group child \"{$childId}\" of \"{$group['id']}\" does not list \"{$group['id']}\" as its parent");
                    }

                    $groupChildCount[$childId] = ($groupChildCount[$childId] ?? 0) + 1;
                    continue;
                }

                $uid = (string) ($child['uid'] ?? '');
                if (array_key_exists($uid, $seenUids)) {
                    $report("Content type \"{$uid}\" appears in more than one group in section \"{$sectionKey}\"");
                } else {
                    $seenUids[$uid] = true;
                }
            }
        }

        // Every non-root group should appear exactly once as a group child.
        foreach ($groups as $group) {
            $count = $groupChildCount[$group['id']] ?? 0;

            if ($group['parent'] === null) {
                if ($count > 0) {
                    $report("Root group \"{$group['id']}\" is listed as a group child of another group in section \"{$sectionKey}\"");
                }
                continue;
            }

            if ($count === 0) {
                $report("Group \"{$group['id']}\" is not listed in the children of its parent \"{$group['parent']}\"");
            } elseif ($count > 1) {
                $report("Group \"{$group['id']}\" is listed {$count} times as a group child in section \"{$sectionKey}\"");
            }
        }
    }

    /** @param array<string, mixed> $file */
    private static function validateGraphRules(array $file, ParsePayload $ctx): void
    {
        self::validateSectionGraph($file['sections']['collectionTypes']['groups'], 'collectionTypes', $ctx);
        self::validateSectionGraph($file['sections']['singleTypes']['groups'], 'singleTypes', $ctx);
    }

    public static function contentStructureFileSchema(): ZodType
    {
        return self::contentStructureFileObjectSchema()
            ->superRefine(static fn (mixed $file, ParsePayload $ctx) => self::uniqueGroupIdsAcrossFile($file, $ctx))
            ->superRefine(static fn (mixed $file, ParsePayload $ctx) => self::validateGraphRules($file, $ctx));
    }

    /** `String.prototype.trim()`. */
    private static function jsTrim(string $value): string
    {
        return (string) preg_replace('/^[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+|[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $value);
    }
}
