<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Controllers\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Controllers\Validation\ContentStructure;

/** Port of server/src/controllers/validation/__tests__/content-structure.test.ts. */
final class ContentStructureTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function validFile(): array
    {
        return [
            'version' => 1,
            'sections' => [
                'collectionTypes' => [
                    'groups' => [
                        [
                            'id' => 'grp_marketing',
                            'name' => 'Marketing',
                            'parent' => null,
                            'children' => [
                                ['type' => 'contentType', 'uid' => 'api::article.article'],
                                ['type' => 'group', 'id' => 'grp_blog'],
                            ],
                        ],
                        [
                            'id' => 'grp_blog',
                            'name' => 'Blog',
                            'parent' => 'grp_marketing',
                            'children' => [['type' => 'contentType', 'uid' => 'api::author.author']],
                        ],
                    ],
                ],
                'singleTypes' => ['groups' => []],
            ],
        ];
    }

    /** @param list<array<string, mixed>> $groups */
    private static function file(array $groups, array $singleTypeGroups = []): array
    {
        return ['version' => 1, 'sections' => ['collectionTypes' => ['groups' => $groups], 'singleTypes' => ['groups' => $singleTypeGroups]]];
    }

    private static function ok(mixed $file): bool
    {
        return ContentStructure::contentStructureFileSchema()->safeParse($file)['success'];
    }

    public function testStructuralShape(): void
    {
        self::assertTrue(self::ok(self::validFile()));
        self::assertFalse(self::ok([...self::validFile(), 'version' => 2]));

        $file = self::validFile();
        unset($file['sections']['singleTypes']);
        self::assertFalse(self::ok($file));

        // accepts a full tree whose ids were hand-authored (regression: QA-CTBS-001)
        self::assertTrue(self::ok(self::file([
            ['id' => 'g1', 'name' => 'Marketing', 'parent' => null, 'children' => [['type' => 'group', 'id' => 'g2']]],
            ['id' => 'g2', 'name' => 'Blog', 'parent' => 'g1', 'children' => []],
        ])));

        // accepts opaque non-empty ids and resolves parent and child references exactly
        self::assertTrue(self::ok(self::file([
            ['id' => 'g1', 'name' => 'Root', 'parent' => null, 'children' => [['type' => 'group', 'id' => 'my-folder']]],
            ['id' => 'my-folder', 'name' => 'Child', 'parent' => 'g1', 'children' => [['type' => 'group', 'id' => 'grp_abc12345']]],
            ['id' => 'grp_abc12345', 'name' => 'Leaf', 'parent' => 'my-folder', 'children' => []],
        ])));
    }

    /** @return iterable<string, array{string}> */
    public static function groupIds(): iterable
    {
        foreach (['grp_blog', 'grp_1a2b3c4d5e', 'grp_marketing', 'g1', 'blog', 'GRP_Blog', 'my-folder'] as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('groupIds')]
    public function testAcceptsGroupId(string $id): void
    {
        self::assertTrue(ContentStructure::contentStructureGroupSchema()->safeParse(['id' => $id, 'name' => 'X', 'parent' => null, 'children' => []])['success']);
    }

    public function testRejectsAnEmptyGroupId(): void
    {
        self::assertFalse(ContentStructure::contentStructureGroupSchema()->safeParse(['id' => '', 'name' => 'X', 'parent' => null, 'children' => []])['success']);
    }

    public function testGroupName(): void
    {
        $withName = static fn (string $name): array => ['id' => 'grp_test', 'name' => $name, 'parent' => null, 'children' => []];

        self::assertTrue(ContentStructure::contentStructureGroupSchema()->safeParse($withName('Marketing'))['success']);
        foreach (['', '   ', ' Marketing', 'Marketing '] as $name) {
            self::assertFalse(ContentStructure::contentStructureGroupSchema()->safeParse($withName($name))['success'], var_export($name, true));
        }
    }

    public function testChildDiscriminatedUnion(): void
    {
        $schema = ContentStructure::contentStructureChildSchema();

        self::assertTrue($schema->safeParse(['type' => 'contentType', 'uid' => 'api::article.article'])['success']);
        self::assertTrue($schema->safeParse(['type' => 'contentType', 'uid' => 'plugin::users-permissions.user'])['success']);
        self::assertFalse($schema->safeParse(['type' => 'contentType', 'uid' => 'not-a-uid'])['success']);
        self::assertTrue($schema->safeParse(['type' => 'group', 'id' => 'grp_blog'])['success']);
        self::assertFalse($schema->safeParse(['type' => 'folder', 'id' => 'grp_blog'])['success']);
    }

    public function testGraphRules(): void
    {
        // rejects duplicate group ids across both sections
        $file = self::validFile();
        $file['sections']['singleTypes']['groups'][] = ['id' => 'grp_blog', 'name' => 'Blog', 'parent' => null, 'children' => []];
        self::assertFalse(self::ok($file));

        // rejects duplicate sibling names under the same parent (case-insensitive)
        $file = self::validFile();
        $file['sections']['collectionTypes']['groups'][] = ['id' => 'grp_other', 'name' => 'marketing', 'parent' => null, 'children' => []];
        self::assertFalse(self::ok($file));

        // accepts the same name under different parents (B2B/B2C France)
        self::assertTrue(self::ok(self::file([
            ['id' => 'grp_business', 'name' => 'B2B', 'parent' => null, 'children' => [['type' => 'group', 'id' => 'grp_franceb']]],
            ['id' => 'grp_franceb', 'name' => 'France', 'parent' => 'grp_business', 'children' => []],
            ['id' => 'grp_consumer', 'name' => 'B2C', 'parent' => null, 'children' => [['type' => 'group', 'id' => 'grp_francec']]],
            ['id' => 'grp_francec', 'name' => 'France', 'parent' => 'grp_consumer', 'children' => []],
        ])));

        // rejects a parent reference into the other section
        self::assertFalse(self::ok(self::file(
            [['id' => 'grp_orphan', 'name' => 'X', 'parent' => 'grp_lonely', 'children' => []]],
            [['id' => 'grp_lonely', 'name' => 'S', 'parent' => null, 'children' => []]],
        )));

        // rejects references that differ from an opaque id only by trailing whitespace
        self::assertFalse(self::ok(self::file([
            ['id' => 'my-folder', 'name' => 'Folder', 'parent' => null, 'children' => []],
            ['id' => 'g1', 'name' => 'Child', 'parent' => 'my-folder ', 'children' => []],
        ])));
        self::assertFalse(self::ok(self::file([
            ['id' => 'g1', 'name' => 'Parent', 'parent' => null, 'children' => [['type' => 'group', 'id' => 'my-folder ']]],
            ['id' => 'my-folder', 'name' => 'Folder', 'parent' => 'g1', 'children' => []],
        ])));

        // rejects parent cycles
        self::assertFalse(self::ok(self::file([
            ['id' => 'grp_alpha', 'name' => 'A', 'parent' => 'grp_bravo', 'children' => [['type' => 'group', 'id' => 'grp_bravo']]],
            ['id' => 'grp_bravo', 'name' => 'B', 'parent' => 'grp_alpha', 'children' => [['type' => 'group', 'id' => 'grp_alpha']]],
        ])));

        // rejects depth > 3, accepts depth == 3
        $chain = static function (int $depth): array {
            $groups = [];
            for ($level = 1; $level <= $depth; $level++) {
                $groups[] = [
                    'id' => "grp_level{$level}",
                    'name' => "L{$level}",
                    'parent' => $level === 1 ? null : 'grp_level' . ($level - 1),
                    'children' => $level < $depth ? [['type' => 'group', 'id' => 'grp_level' . ($level + 1)]] : [],
                ];
            }

            return self::file($groups);
        };
        self::assertTrue(self::ok($chain(3)));
        self::assertFalse(self::ok($chain(4)));

        // rejects a group child referencing a nonexistent group
        $file = self::validFile();
        $file['sections']['collectionTypes']['groups'][0]['children'][] = ['type' => 'group', 'id' => 'grp_ghost'];
        self::assertFalse(self::ok($file));

        // rejects a group child whose target's parent is not the containing group
        $file = self::validFile();
        $file['sections']['collectionTypes']['groups'][1]['parent'] = null;
        self::assertFalse(self::ok($file));

        // rejects a non-root group absent from its parent children
        $file = self::validFile();
        $file['sections']['collectionTypes']['groups'][0]['children'] = array_values(array_filter(
            $file['sections']['collectionTypes']['groups'][0]['children'],
            static fn (array $child): bool => $child['type'] !== 'group',
        ));
        self::assertFalse(self::ok($file));

        // rejects a uid appearing in two groups of one section
        $file = self::validFile();
        $file['sections']['collectionTypes']['groups'][1]['children'][] = ['type' => 'contentType', 'uid' => 'api::article.article'];
        self::assertFalse(self::ok($file));
    }
}
