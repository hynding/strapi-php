<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops;

use Strapi\Generators\Plop;
use Strapi\Generators\Plops\Prompts\GetDestinationPrompts;
use Strapi\Generators\Plops\Utils\GetFilePath;
use Strapi\Generators\Plops\Utils\PluginIndexActions;
use Strapi\Generators\Plops\Utils\ValidateInput;

/** Port of src/plops/api.ts: `strapi generate api`. */
final class Api
{
    public function __invoke(Plop $plop): void
    {
        // API generator
        $plop->setGenerator('api', [
            'description' => 'Generate a basic API',
            'prompts' => [
                [
                    'type' => 'input',
                    'name' => 'id',
                    'message' => 'API name',
                    'validate' => static fn (mixed $input): bool|string => ValidateInput::validateInput($input),
                ],
                [
                    'type' => 'confirm',
                    'name' => 'isPluginApi',
                    'message' => 'Is this API for a plugin?',
                ],
                [
                    'when' => static fn (array $answers): bool => (bool) ($answers['isPluginApi'] ?? false),
                    'type' => 'list',
                    'name' => 'plugin',
                    'message' => 'Plugin name',
                    'choices' => static function () use ($plop): array {
                        $pluginsPath = $plop->getDestBasePath() . '/plugins';
                        if (!file_exists($pluginsPath)) {
                            throw new \RuntimeException('Couldn\'t find a "plugins" directory');
                        }

                        $pluginsDirContent = GetDestinationPrompts::directories($pluginsPath);

                        if ($pluginsDirContent === []) {
                            throw new \RuntimeException('The "plugins" directory is empty');
                        }

                        return $pluginsDirContent;
                    },
                ],
            ],
            'actions' => static function (array $answers) use ($plop): array {
                if ($answers === []) {
                    return [];
                }

                $isPluginApi = (bool) ($answers['isPluginApi'] ?? false);
                $plugin = $answers['plugin'] ?? null;
                $destination = $answers['destination'] ?? null;
                $filePath = GetFilePath::getFilePath(is_string($destination) && $destination !== '' ? $destination : ($isPluginApi && $plugin ? 'plugin' : 'new'));

                $baseActions = [
                    [
                        'type' => 'add',
                        'path' => "{$filePath}/controllers/{{ id }}.php",
                        'templateFile' => 'templates/php/controller.php.tpl',
                    ],
                    [
                        'type' => 'add',
                        'path' => "{$filePath}/services/{{ id }}.php",
                        'templateFile' => 'templates/php/service.php.tpl',
                    ],
                    [
                        'type' => 'add',
                        'path' => "{$filePath}/routes/" . ($plugin ? 'content-api/' : '') . '{{ id }}.php',
                        'templateFile' => 'templates/php/single-route.php.tpl',
                    ],
                ];

                if ($isPluginApi) {
                    $baseActions = [
                        ...$baseActions,
                        ...PluginIndexActions::actions($plop, $filePath, $answers, ['controllers', 'services', 'routes'], (string) $answers['id']),
                    ];
                }

                return $baseActions;
            },
        ]);
    }
}
