<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Prompts;

use Strapi\Generators\Inquirer;
use Strapi\Generators\Plop;
use Strapi\Generators\Plops\Utils\ContentStructure;

/**
 * Port of src/plops/prompts/get-folder-prompts.ts.
 *
 * Asks where to place the generated content type in the content-structure folder tree.
 *
 * Inquirer list choices are scalars here: the existing folders' values are `existing:<id>` and
 * "Create a new folder" is `new` (upstream uses `{ kind, id }` objects).
 *
 * @phpstan-import-type FolderSelection from ContentStructure
 */
final class GetFolderPrompts
{
    public const string NEW_FOLDER = 'new';

    public const string EXISTING_PREFIX = 'existing:';

    /**
     * @param array{kind: string, destination?: string|null} $context
     * @return array{folder?: FolderSelection}
     */
    public static function getFolderPrompts(Inquirer $inquirer, Plop $plop, array $context): array
    {
        $kind = $context['kind'];
        $destination = $context['destination'] ?? null;

        // A plugin content type only loads once the plugin is enabled in the app config.
        // Since it can't be determined in this context whether a plugin is enabled or not,
        // a folder assignment could be pruned on the next write to groups.json boot if its
        // referenced content type is found not to exist. I've opted to sidestep this by
        // disabling folder assignment for plugin content-types for the time being.

        if ($destination === 'plugin') {
            return [];
        }

        $read = ContentStructure::readContentStructureFile($plop->getDestBasePath());

        if ($read['status'] === 'invalid') {
            $inquirer->warn('The content-structure file (src/content-structure/groups.json) could not be read. Skipping folder assignment.');

            return [];
        }

        $sectionKey = ContentStructure::sectionKeyForKind($kind);
        $groups = $read['status'] === 'ok' ? ContentStructure::getSectionGroups($read['file'], $sectionKey) : [];

        if ($groups === null) {
            $inquirer->warn("The \"{$sectionKey}\" section of src/content-structure/groups.json is malformed. Skipping folder assignment.");

            return [];
        }

        $addToFolder = $inquirer->prompt([
            [
                'message' => 'Add this content type to a folder?',
                'name' => 'addToFolder',
                'type' => 'confirm',
                'default' => false,
            ],
        ])['addToFolder'] ?? false;

        if (!$addToFolder) {
            return [];
        }

        $folderChoices = ContentStructure::listFolderChoices($groups);

        $folderChoicesOptions = array_map(
            static fn (array $choice): array => ['value' => self::EXISTING_PREFIX . $choice['value'], 'name' => $choice['name']],
            $folderChoices,
        );

        if ($folderChoicesOptions !== []) {
            $folderChoice = $inquirer->prompt([
                [
                    'message' => 'Select a folder',
                    'name' => 'folderChoice',
                    'type' => 'list',
                    'default' => 0,
                    'choices' => [['name' => 'Create a new folder', 'value' => self::NEW_FOLDER], ...$folderChoicesOptions],
                ],
            ])['folderChoice'] ?? self::NEW_FOLDER;

            if (is_string($folderChoice) && str_starts_with($folderChoice, self::EXISTING_PREFIX)) {
                return ['folder' => ['targetGroupId' => substr($folderChoice, strlen(self::EXISTING_PREFIX))]];
            }
        }

        $folderName = $inquirer->prompt([
            [
                'message' => 'Name of the new folder',
                'name' => 'folderName',
                'type' => 'input',
                'validate' => static function (mixed $input): bool|string {
                    if (!is_string($input) || trim($input) === '') {
                        return 'The folder name cannot be empty';
                    }

                    return true;
                },
            ],
        ])['folderName'] ?? '';

        $trimmedName = trim(is_string($folderName) ? $folderName : '');

        // A new folder whose name matches an existing root folder reuses that folder instead of creating a duplicate.
        $existingRootFolder = ContentStructure::findRootFolderByName($groups, $trimmedName);

        if ($existingRootFolder !== null) {
            return ['folder' => ['targetGroupId' => (string) $existingRootFolder->id]];
        }

        return ['folder' => ['newFolderName' => $trimmedName]];
    }
}
