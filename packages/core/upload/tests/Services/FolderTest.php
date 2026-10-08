<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Services;

use Strapi\Tests\AppTestCase;
use Strapi\Upload\Utils\Utils;

/** Port of server/src/services/__tests__/folder.test.ts (on a booted app), plus getStructure. */
final class FolderTest extends AppTestCase
{
    public function testSetPathIdAndPath(): void
    {
        $folderService = Utils::getService('folder', self::strapi());
        $parent = $folderService->create(['name' => 'parent']);

        foreach ([
            [['parent' => $parent['id']], '#^' . preg_quote($parent['path'], '#') . '/[0-9]*$#'],
            [[], '#^/[0-9]*$#'],
            [['parent' => null], '#^/[0-9]*$#'],
        ] as [$folder, $expectedPath]) {
            $result = $folderService->setPathIdAndPath($folder);

            self::assertIsInt($result['pathId']);
            self::assertMatchesRegularExpression($expectedPath, $result['path']);
            foreach ($folder as $key => $value) {
                self::assertSame($value, $result[$key]);
            }
        }
    }

    public function testGetStructureNestsAndSortsByName(): void
    {
        $folderService = Utils::getService('folder', self::strapi());
        $b = $folderService->create(['name' => 'zz-b']);
        $a = $folderService->create(['name' => 'zz-a']);
        $child = $folderService->create(['name' => 'child', 'parent' => $a['id']]);

        $roots = array_values(array_filter($folderService->getStructure(), static fn (array $f): bool => str_starts_with((string) $f['name'], 'zz-')));

        self::assertSame([
            ['id' => $a['id'], 'name' => 'zz-a', 'children' => [['id' => $child['id'], 'name' => 'child', 'children' => []]]],
            ['id' => $b['id'], 'name' => 'zz-b', 'children' => []],
        ], $roots);
    }

    public function testUpdateMovesTheSubtree(): void
    {
        $folderService = Utils::getService('folder', self::strapi());
        $from = $folderService->create(['name' => 'from']);
        $to = $folderService->create(['name' => 'to']);
        $nested = $folderService->create(['name' => 'nested', 'parent' => $from['id']]);

        $folderService->update($from['id'], ['name' => 'from', 'parent' => $to['id']], []);

        $moved = self::strapi()->db()->query('plugin::upload.folder')->findOne(['where' => ['id' => $nested['id']]]);
        self::assertSame("{$to['path']}/{$from['pathId']}/{$nested['pathId']}", $moved['path']);
    }
}
