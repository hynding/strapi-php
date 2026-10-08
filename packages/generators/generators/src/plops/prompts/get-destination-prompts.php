<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Prompts;

/**
 * Port of src/plops/prompts/get-destination-prompts.ts.
 *
 * @phpstan-import-type Question from \Strapi\Generators\Plop
 */
final class GetDestinationPrompts
{
    /**
     * @param array{rootFolder?: bool} $options
     * @return list<Question>
     */
    public static function getDestinationPrompts(string $action, string $basePath, array $options = []): array
    {
        $rootFolder = $options['rootFolder'] ?? false;

        return [
            [
                'type' => 'list',
                'name' => 'destination',
                'message' => "Where do you want to add this {$action}?",
                'choices' => [
                    ...($rootFolder
                        ? [['name' => "Add {$action} to root of project", 'value' => 'root']]
                        : [['name' => "Add {$action} to new API", 'value' => 'new']]),
                    ['name' => "Add {$action} to an existing API", 'value' => 'api'],
                    ['name' => "Add {$action} to an existing plugin", 'value' => 'plugin'],
                ],
            ],
            [
                'when' => static fn (array $answers): bool => ($answers['destination'] ?? null) === 'api',
                'type' => 'list',
                'message' => 'Which API is this for?',
                'name' => 'api',
                'choices' => static function () use ($basePath): array {
                    $apiPath = $basePath . '/api';

                    if (!file_exists($apiPath)) {
                        throw new \RuntimeException('Couldn\'t find an "api" directory');
                    }

                    $apiDirContent = self::directories($apiPath);

                    if ($apiDirContent === []) {
                        throw new \RuntimeException('The "api" directory is empty');
                    }

                    return $apiDirContent;
                },
            ],
            [
                'when' => static fn (array $answers): bool => ($answers['destination'] ?? null) === 'plugin',
                'type' => 'list',
                'message' => 'Which plugin is this for?',
                'name' => 'plugin',
                'choices' => static function () use ($basePath): array {
                    $pluginsPath = $basePath . '/plugins';

                    if (!file_exists($pluginsPath)) {
                        throw new \RuntimeException('Couldn\'t find a "plugins" directory');
                    }

                    $pluginsDirContent = self::directories($pluginsPath);

                    if ($pluginsDirContent === []) {
                        throw new \RuntimeException('The "plugins" directory is empty');
                    }

                    return $pluginsDirContent;
                },
            ],
        ];
    }

    /** @return list<string> the sub-directory names, sorted */
    public static function directories(string $path): array
    {
        $entries = array_values(array_filter(
            scandir($path) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($path . '/' . $entry) && !is_link($path . '/' . $entry),
        ));
        sort($entries);

        return $entries;
    }
}
