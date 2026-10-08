<?php

declare(strict_types=1);

namespace Strapi\Generators\Tests\Plops;

use Strapi\Generators\Plop;
use Strapi\Generators\Plops\Prompts\GetFolderPrompts;
use Strapi\Generators\Tests\FakeInquirer;
use Strapi\Generators\Tests\GeneratorsTestCase;

require_once dirname(__DIR__) . '/GeneratorsTestCase.php';
require_once dirname(__DIR__) . '/FakeInquirer.php';

/**
 * Port of src/plops/__tests__/get-folder-prompts.test.ts.
 *
 * Folder choices are scalars in the port: `existing:<id>` for a folder, `new` for "Create a new
 * folder" (upstream: `{ kind: 'existing', id }` / `{ kind: 'new' }`).
 */
final class GetFolderPromptsTest extends GeneratorsTestCase
{
    private string $groupsPath;

    private Plop $plop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groupsPath = $this->outputDirectory . '/src/content-structure/groups.json';
        $this->plop = new Plop($this->outputDirectory . '/src');
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
                            'children' => [
                                ['type' => 'group', 'id' => 'grp_second1'],
                                ['type' => 'group', 'id' => 'grp_first1'],
                            ],
                        ],
                        ['parent' => 'grp_shop1', 'name' => 'First in array', 'id' => 'grp_first1', 'children' => []],
                        ['parent' => 'grp_shop1', 'name' => 'Second in array', 'id' => 'grp_second1', 'children' => []],
                    ],
                ],
                'singleTypes' => ['groups' => []],
            ],
        ];
    }

    /** @return list<string> */
    private static function choiceNames(FakeInquirer $inquirer, int $call): array
    {
        $choices = $inquirer->calls[$call][0]['choices'];
        self::assertIsArray($choices);

        return array_values(array_map(static fn (array $choice): string => $choice['name'], $choices));
    }

    public function testSkipsThePromptEntirelyForThePluginDestination(): void
    {
        $inquirer = new FakeInquirer();

        $result = GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType', 'destination' => 'plugin']);

        self::assertSame([], $result);
        self::assertSame([], $inquirer->calls);
    }

    public function testSkipsWithAWarningWhenTheFileIsUnreadable(): void
    {
        self::outputFile($this->groupsPath, '{ not json');
        $inquirer = new FakeInquirer();

        $result = GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType']);

        self::assertSame([], $result);
        self::assertSame([], $inquirer->calls);
        self::assertStringContainsString('could not be read', $inquirer->warnings[0] ?? '');
    }

    public function testSkipsWithAWarningWhenTheTargetSectionIsMalformed(): void
    {
        self::outputJSON($this->groupsPath, [
            'version' => 1,
            'sections' => ['collectionTypes' => ['groups' => 'nope'], 'singleTypes' => ['groups' => []]],
        ]);
        $inquirer = new FakeInquirer();

        $result = GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType']);

        self::assertSame([], $result);
        self::assertSame([], $inquirer->calls);
        self::assertStringContainsString('malformed', $inquirer->warnings[0] ?? '');
    }

    public function testDecliningTheConfirmReturnsNoAssignment(): void
    {
        $inquirer = new FakeInquirer(['addToFolder' => false]);

        $result = GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType']);

        self::assertSame([], $result);
        self::assertCount(1, $inquirer->calls);

        $questions = $inquirer->calls[0];
        self::assertSame('confirm', $questions[0]['type']);
        self::assertSame('Add this content type to a folder?', $questions[0]['message']);
    }

    public function testConfirmingWithNoExistingFoldersGoesStraightToTheNameInput(): void
    {
        $inquirer = new FakeInquirer(['addToFolder' => true], ['folderName' => 'Blog']);

        $result = GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType']);

        self::assertSame(['folder' => ['newFolderName' => 'Blog']], $result);

        self::assertSame('folderName', $inquirer->calls[1][0]['name']);
    }

    public function testListsCreateANewFolderFirstThenFoldersInTheParentChildrenOrderWithPathLabels(): void
    {
        self::outputJSON($this->groupsPath, self::seededFile());
        $inquirer = new FakeInquirer(['addToFolder' => true], ['folderChoice' => 'existing:grp_shop1']);

        GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType']);

        self::assertSame([
            'Create a new folder',
            'Shop',
            'Shop / Second in array',
            'Shop / First in array',
        ], self::choiceNames($inquirer, 1));
    }

    public function testAnExistingFolderSelectionReturnsItsGroupIdEvenAnIdShapedLikeASentinel(): void
    {
        $seeded = self::seededFile();
        $seeded['sections']['collectionTypes']['groups'][] = ['parent' => null, 'name' => 'Misc', 'id' => 'new', 'children' => []];
        self::outputJSON($this->groupsPath, $seeded);
        $inquirer = new FakeInquirer(['addToFolder' => true], ['folderChoice' => 'existing:new']);

        $result = GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType']);

        self::assertSame(['folder' => ['targetGroupId' => 'new']], $result);
    }

    public function testCreatingAFolderWhoseNameMatchesARootFolderReusesIt(): void
    {
        self::outputJSON($this->groupsPath, self::seededFile());
        $inquirer = new FakeInquirer(['addToFolder' => true], ['folderChoice' => 'new'], ['folderName' => '  shop ']);

        $result = GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType']);

        self::assertSame(['folder' => ['targetGroupId' => 'grp_shop1']], $result);
    }

    public function testCreatingAFolderWithAFreshNameReturnsTheTrimmedName(): void
    {
        self::outputJSON($this->groupsPath, self::seededFile());
        $inquirer = new FakeInquirer(['addToFolder' => true], ['folderChoice' => 'new'], ['folderName' => ' Blog ']);

        $result = GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType']);

        self::assertSame(['folder' => ['newFolderName' => 'Blog']], $result);
    }

    public function testSurfacesADanglingParentFolderAsARootInThePicker(): void
    {
        // Core reparents this to root on boot; the picker must show it so a typed name
        // can never resolve to a folder the list never offered.
        $seeded = self::seededFile();
        $seeded['sections']['collectionTypes']['groups'][] = ['parent' => 'grp_deleted1', 'name' => 'Orphaned', 'id' => 'grp_orphan1', 'children' => []];
        self::outputJSON($this->groupsPath, $seeded);
        $inquirer = new FakeInquirer(['addToFolder' => true], ['folderChoice' => 'existing:grp_orphan1']);

        $result = GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType']);

        self::assertSame([
            'Create a new folder',
            'Shop',
            'Shop / Second in array',
            'Shop / First in array',
            'Orphaned',
        ], self::choiceNames($inquirer, 1));
        self::assertSame(['folder' => ['targetGroupId' => 'grp_orphan1']], $result);
    }

    public function testTypingTheNameOfADanglingParentFolderReusesTheFolderNowShownInThePicker(): void
    {
        $seeded = self::seededFile();
        $seeded['sections']['collectionTypes']['groups'][] = ['parent' => 'grp_deleted1', 'name' => 'Orphaned', 'id' => 'grp_orphan1', 'children' => []];
        self::outputJSON($this->groupsPath, $seeded);
        $inquirer = new FakeInquirer(['addToFolder' => true], ['folderChoice' => 'new'], ['folderName' => 'orphaned']);

        $result = GetFolderPrompts::getFolderPrompts($inquirer, $this->plop, ['kind' => 'collectionType']);

        self::assertSame(['folder' => ['targetGroupId' => 'grp_orphan1']], $result);
    }
}
