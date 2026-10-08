<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops;

use Strapi\Generators\Plop;
use Strapi\Generators\Plops\Prompts\GetDestinationPrompts;
use Strapi\Generators\Plops\Utils\GetFilePath;
use Strapi\Generators\Plops\Utils\PluginIndexActions;
use Strapi\Generators\Plops\Utils\ValidateInput;

/** Port of src/plops/controller.ts: `strapi generate controller`. */
final class Controller
{
    public function __invoke(Plop $plop): void
    {
        // controller generator
        $plop->setGenerator('controller', [
            'description' => 'Generate a controller for an API',
            'prompts' => [
                [
                    'type' => 'input',
                    'name' => 'id',
                    'message' => 'Controller name',
                    'validate' => static fn (mixed $input): bool|string => ValidateInput::validateInput($input),
                ],
                ...GetDestinationPrompts::getDestinationPrompts('controller', $plop->getDestBasePath()),
            ],
            'actions' => static function (array $answers) use ($plop): array {
                if ($answers === []) {
                    return [];
                }

                $destination = $answers['destination'] ?? null;
                $filePath = GetFilePath::getFilePath(is_string($destination) ? $destination : null);

                $baseActions = [
                    [
                        'type' => 'add',
                        'path' => "{$filePath}/controllers/{{ id }}.php",
                        'templateFile' => 'templates/php/controller.php.tpl',
                    ],
                ];

                if (($answers['plugin'] ?? null) !== null && $answers['plugin'] !== '') {
                    $baseActions = [
                        ...$baseActions,
                        ...PluginIndexActions::actions($plop, $filePath, $answers, ['controllers'], (string) $answers['id']),
                    ];
                }

                return $baseActions;
            },
        ]);
    }
}
