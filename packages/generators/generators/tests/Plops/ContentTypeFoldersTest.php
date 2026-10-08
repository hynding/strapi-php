<?php

declare(strict_types=1);

namespace Strapi\Generators\Tests\Plops;

use PHPUnit\Framework\Attributes\DataProvider;
use Strapi\Generators\Generators;
use Strapi\Generators\Tests\GeneratorsTestCase;

require_once dirname(__DIR__) . '/GeneratorsTestCase.php';

/** Port of src/plops/__tests__/content-type-folders.test.ts. */
final class ContentTypeFoldersTest extends GeneratorsTestCase
{
    private const string GROUP_ID = '/^grp_[a-z0-9]{24}$/';

    private string $groupsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groupsPath = $this->outputDirectory . '/src/content-structure/groups.json';
    }

    /** @param array<string, mixed> $answers */
    private function generate(array $answers): void
    {
        Generators::generate('content-type', [
            'displayName' => 'article',
            'singularName' => 'article',
            'pluralName' => 'articles',
            'kind' => 'collectionType',
            'id' => 'article',
            'destination' => 'new',
            'bootstrapApi' => false,
            'attributes' => [],
            ...$answers,
        ], ['dir' => $this->outputDirectory]);
    }

    /** @return array<string, mixed> */
    private static function seededFile(): array
    {
        return [
            'version' => 1,
            'sections' => [
                'collectionTypes' => [
                    'groups' => [
                        [
                            'parent' => null,
                            'name' => 'Shop',
                            'id' => 'grp_shop1',
                            'children' => [['type' => 'group', 'id' => 'grp_products1']],
                        ],
                        [
                            'parent' => 'grp_shop1',
                            'name' => 'Products',
                            'id' => 'grp_products1',
                            'children' => [['type' => 'contentType', 'uid' => 'api::product.product']],
                        ],
                    ],
                ],
                'singleTypes' => [
                    'groups' => [
                        [
                            'parent' => null,
                            'name' => 'Site pages',
                            'id' => 'grp_pages1',
                            'children' => [['type' => 'contentType', 'uid' => 'api::homepage.homepage']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $children
     */
    private static function assertNewRootFolder(array $group, string $name, array $children): void
    {
        self::assertSame(['id', 'children', 'parent', 'name'], array_keys($group));
        self::assertNull($group['parent']);
        self::assertSame($name, $group['name']);
        self::assertMatchesRegularExpression(self::GROUP_ID, $group['id']);
        self::assertSame($children, $group['children']);
    }

    /** @param callable(): void $fn */
    private static function assertThrows(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }
        self::fail("Expected an error containing: {$message}");
    }

    public function testCreatesGroupsJsonWithANewRootFolder(): void
    {
        $this->generate(['folder' => ['newFolderName' => 'Blog']]);

        $written = self::readJSON($this->groupsPath);
        self::assertSame(['version', 'sections'], array_keys($written));
        self::assertSame(1, $written['version']);
        self::assertSame(['groups' => []], $written['sections']['singleTypes']);
        self::assertCount(1, $written['sections']['collectionTypes']['groups']);
        self::assertNewRootFolder($written['sections']['collectionTypes']['groups'][0], 'Blog', [['type' => 'contentType', 'uid' => 'api::article.article']]);
    }

    public function testAddsTheContentTypeToAnExistingFolderAndPreservesTheRestOfTheFile(): void
    {
        self::outputJSON($this->groupsPath, [...self::seededFile(), 'future' => ['unknownKey' => true, 'empty' => new \stdClass()]]);

        $this->generate(['folder' => ['targetGroupId' => 'grp_products1']]);

        $expected = self::seededFile();
        $expected['sections']['collectionTypes']['groups'][1]['children'][] = ['type' => 'contentType', 'uid' => 'api::article.article'];

        self::assertSame([...$expected, 'future' => ['unknownKey' => true, 'empty' => []]], self::readJSON($this->groupsPath));
        self::assertStringContainsString('"empty": {}', self::read($this->groupsPath));
    }

    public function testReusesAnExistingRootFolderWhenTheNewNameMatchesCaseInsensitively(): void
    {
        self::outputJSON($this->groupsPath, self::seededFile());

        $this->generate(['folder' => ['newFolderName' => '  shop ']]);

        $written = self::readJSON($this->groupsPath);

        self::assertCount(2, $written['sections']['collectionTypes']['groups']);
        self::assertSame([
            ['type' => 'group', 'id' => 'grp_products1'],
            ['type' => 'contentType', 'uid' => 'api::article.article'],
        ], $written['sections']['collectionTypes']['groups'][0]['children']);
    }

    public function testDetachesTheContentTypeFromItsPreviousFolderInTheSection(): void
    {
        $seeded = self::seededFile();
        $seeded['sections']['collectionTypes']['groups'][1]['children'][] = ['type' => 'contentType', 'uid' => 'api::article.article'];
        self::outputJSON($this->groupsPath, $seeded);

        $this->generate(['folder' => ['newFolderName' => 'Blog']]);

        $written = self::readJSON($this->groupsPath);

        self::assertSame([['type' => 'contentType', 'uid' => 'api::product.product']], $written['sections']['collectionTypes']['groups'][1]['children']);
        self::assertNewRootFolder($written['sections']['collectionTypes']['groups'][2], 'Blog', [['type' => 'contentType', 'uid' => 'api::article.article']]);
    }

    public function testASingleTypeLandsInTheSingleTypesSection(): void
    {
        self::outputJSON($this->groupsPath, self::seededFile());

        $this->generate(['kind' => 'singleType', 'folder' => ['targetGroupId' => 'grp_pages1']]);

        $written = self::readJSON($this->groupsPath);

        self::assertSame([
            ['type' => 'contentType', 'uid' => 'api::homepage.homepage'],
            ['type' => 'contentType', 'uid' => 'api::article.article'],
        ], $written['sections']['singleTypes']['groups'][0]['children']);
        self::assertSame(self::seededFile()['sections']['collectionTypes'], $written['sections']['collectionTypes']);
    }

    public function testLeavesGroupsJsonUntouchedWhenNoFolderIsProvided(): void
    {
        self::outputJSON($this->groupsPath, self::seededFile());
        $before = self::read($this->groupsPath);

        $this->generate([]);

        self::assertSame($before, self::read($this->groupsPath));
    }

    public function testDoesNotCreateGroupsJsonWhenNoFolderIsProvided(): void
    {
        $this->generate([]);

        self::assertFileDoesNotExist($this->groupsPath);
    }

    public function testRefusesToTouchAnUnparseableGroupsJson(): void
    {
        self::outputFile($this->groupsPath, '{ not json');

        self::assertThrows(fn () => $this->generate(['folder' => ['newFolderName' => 'Blog']]), 'is not a valid content-structure file');

        self::assertSame('{ not json', self::read($this->groupsPath));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidFiles(): iterable
    {
        yield 'an unknown version' => [[...self::seededFile(), 'version' => 2]];
        yield 'a missing sections object' => [['version' => 1]];
        yield 'an array-valued sections' => [['version' => 1, 'sections' => []]];
    }

    /** @param array<string, mixed> $file */
    #[DataProvider('invalidFiles')]
    public function testRefusesToTouchAFileWith(array $file): void
    {
        self::outputJSON($this->groupsPath, $file);
        $before = self::read($this->groupsPath);

        self::assertThrows(fn () => $this->generate(['folder' => ['newFolderName' => 'Blog']]), 'is not a valid content-structure file');

        self::assertSame($before, self::read($this->groupsPath));
    }

    public function testRefusesToTouchAFileWhoseTargetSectionIsMalformed(): void
    {
        self::outputJSON($this->groupsPath, [
            'version' => 1,
            'sections' => ['collectionTypes' => ['groups' => 'nope'], 'singleTypes' => ['groups' => []]],
        ]);
        $before = self::read($this->groupsPath);

        self::assertThrows(fn () => $this->generate(['folder' => ['newFolderName' => 'Blog']]), 'the "collectionTypes" section');

        self::assertSame($before, self::read($this->groupsPath));
    }

    public function testRejectsAnEmptyNewFolderNameWithoutCreatingTheFile(): void
    {
        self::assertThrows(fn () => $this->generate(['folder' => ['newFolderName' => '   ']]), 'must be a non-empty string');

        self::assertFileDoesNotExist($this->groupsPath);
    }

    public function testThrowsWhenAFolderIsRequestedButNoUidCanBeDerived(): void
    {
        self::assertThrows(fn () => $this->generate(['destination' => 'api', 'folder' => ['newFolderName' => 'Blog']]), 'could not determine the content type uid');

        self::assertFileDoesNotExist($this->groupsPath);
    }

    public function testSynthesizesAMissingSectionInsteadOfFailing(): void
    {
        self::outputJSON($this->groupsPath, ['version' => 1, 'sections' => ['collectionTypes' => self::seededFile()['sections']['collectionTypes']]]);

        $this->generate(['kind' => 'singleType', 'folder' => ['newFolderName' => 'Pages']]);

        $written = self::readJSON($this->groupsPath);

        self::assertCount(1, $written['sections']['singleTypes']['groups']);
        self::assertNewRootFolder($written['sections']['singleTypes']['groups'][0], 'Pages', [['type' => 'contentType', 'uid' => 'api::article.article']]);
        self::assertSame(self::seededFile()['sections']['collectionTypes'], $written['sections']['collectionTypes']);
    }

    public function testDoesNotReuseANestedFolderWhoseNameMatchesTheNewFolderName(): void
    {
        self::outputJSON($this->groupsPath, self::seededFile());

        $this->generate(['folder' => ['newFolderName' => 'products']]);

        $written = self::readJSON($this->groupsPath);

        self::assertCount(3, $written['sections']['collectionTypes']['groups']);
        self::assertSame([['type' => 'contentType', 'uid' => 'api::product.product']], $written['sections']['collectionTypes']['groups'][1]['children']);
        self::assertNewRootFolder($written['sections']['collectionTypes']['groups'][2], 'products', [['type' => 'contentType', 'uid' => 'api::article.article']]);
    }

    public function testReusesADanglingParentFolderTheCoreReaderWouldReparentToRoot(): void
    {
        $seeded = self::seededFile();
        $seeded['sections']['collectionTypes']['groups'][] = ['parent' => 'grp_deleted1', 'name' => 'Blog', 'id' => 'grp_dangling1', 'children' => []];
        self::outputJSON($this->groupsPath, $seeded);

        $this->generate(['folder' => ['newFolderName' => 'blog']]);

        $written = self::readJSON($this->groupsPath);

        self::assertCount(3, $written['sections']['collectionTypes']['groups']);
        self::assertSame([['type' => 'contentType', 'uid' => 'api::article.article']], $written['sections']['collectionTypes']['groups'][2]['children']);
    }

    public function testToleratesMalformedGroupEntriesAndPreservesThemVerbatim(): void
    {
        $seeded = self::seededFile();
        $seeded['sections']['collectionTypes']['groups'][] = null;
        $seeded['sections']['collectionTypes']['groups'][] = ['id' => 'grp_broken1', 'name' => 'Broken'];
        self::outputJSON($this->groupsPath, $seeded);

        $this->generate(['folder' => ['targetGroupId' => 'grp_products1']]);

        $written = self::readJSON($this->groupsPath);

        self::assertContains(['type' => 'contentType', 'uid' => 'api::article.article'], $written['sections']['collectionTypes']['groups'][1]['children']);
        self::assertNull($written['sections']['collectionTypes']['groups'][2]);
        self::assertSame(['id' => 'grp_broken1', 'name' => 'Broken'], $written['sections']['collectionTypes']['groups'][3]);
    }

    public function testRejectsATargetFolderWhoseGroupEntryIsMalformed(): void
    {
        $seeded = self::seededFile();
        $seeded['sections']['collectionTypes']['groups'][] = ['id' => 'grp_broken1', 'name' => 'Broken'];
        self::outputJSON($this->groupsPath, $seeded);
        $before = self::read($this->groupsPath);

        self::assertThrows(fn () => $this->generate(['folder' => ['targetGroupId' => 'grp_broken1']]), 'No usable folder with id "grp_broken1"');

        self::assertSame($before, self::read($this->groupsPath));
    }

    public function testRejectsAnUnknownTargetFolderIdWithoutWriting(): void
    {
        self::outputJSON($this->groupsPath, self::seededFile());
        $before = self::read($this->groupsPath);

        self::assertThrows(fn () => $this->generate(['folder' => ['targetGroupId' => 'grp_missing1']]), 'No usable folder with id "grp_missing1"');

        self::assertSame($before, self::read($this->groupsPath));
    }

    public function testGeneratesNoContentTypeFilesWhenTheFolderSelectionIsInvalid(): void
    {
        self::outputJSON($this->groupsPath, self::seededFile());
        $schemaPath = $this->outputDirectory . '/src/api/article/content-types/article/schema.json';

        self::assertThrows(fn () => $this->generate(['folder' => ['targetGroupId' => 'grp_missing1']]), 'No usable folder with id "grp_missing1"');

        self::assertFileDoesNotExist($schemaPath);
    }

    public function testRefusesToReassignTheFolderOfAContentTypeThatAlreadyExistsOnDisk(): void
    {
        // The article content type already exists and is filed in Products. Re-running
        // the generator would fail at the schema write, so the folder move must not persist.
        $schemaPath = $this->outputDirectory . '/src/api/article/content-types/article/schema.json';
        self::outputFile($schemaPath, '{}');

        $seeded = self::seededFile();
        $seeded['sections']['collectionTypes']['groups'][1]['children'][] = ['type' => 'contentType', 'uid' => 'api::article.article'];
        self::outputJSON($this->groupsPath, $seeded);
        $before = self::read($this->groupsPath);

        self::assertThrows(fn () => $this->generate(['folder' => ['newFolderName' => 'Blog']]), 'Content type "api::article.article" already exists');

        self::assertSame($before, self::read($this->groupsPath));
    }

    public function testDoesNotCreateGroupsJsonWhenAGeneratedFileFailsToWriteNewFolder(): void
    {
        $schemaDir = $this->outputDirectory . '/src/api/article/content-types/article';
        self::outputFile($schemaDir, 'blocks the schema directory');

        self::assertThrows(fn () => $this->generate(['folder' => ['newFolderName' => 'Newsroom']]), '');

        self::assertFileDoesNotExist($this->groupsPath);
    }

    public function testDoesNotAssignAFolderWhenAGeneratedFileFailsToWriteExistingFolder(): void
    {
        self::outputJSON($this->groupsPath, self::seededFile());
        $before = self::read($this->groupsPath);

        $schemaDir = $this->outputDirectory . '/src/api/article/content-types/article';
        self::outputFile($schemaDir, 'blocks the schema directory');

        self::assertThrows(fn () => $this->generate(['folder' => ['targetGroupId' => 'grp_products1']]), '');

        self::assertSame($before, self::read($this->groupsPath));
    }
}
