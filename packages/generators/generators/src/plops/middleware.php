<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops;

use Strapi\Generators\Plop;
use Strapi\Generators\Plops\Prompts\GetDestinationPrompts;
use Strapi\Generators\Plops\Utils\GetFilePath;
use Strapi\Generators\Plops\Utils\PluginIndexActions;
use Strapi\Generators\Plops\Utils\ValidateInput;

/** Port of src/plops/middleware.ts: `strapi generate middleware`. */
final class Middleware
{
    public function __invoke(Plop $plop): void
    {
        // middleware generator
        $plop->setGenerator('middleware', [
            'description' => 'Generate a middleware for an API',
            'prompts' => [
                [
                    'type' => 'input',
                    'name' => 'name',
                    'message' => 'Middleware name',
                    'validate' => static fn (mixed $input): bool|string => ValidateInput::validateInput($input),
                ],
                ...GetDestinationPrompts::getDestinationPrompts('middleware', $plop->getDestBasePath(), ['rootFolder' => true]),
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
                        'path' => "{$filePath}/middlewares/{{ name }}.php",
                        'templateFile' => 'templates/php/middleware.php.tpl',
                    ],
                ];

                if (($answers['plugin'] ?? null) !== null && $answers['plugin'] !== '') {
                    $baseActions = [
                        ...$baseActions,
                        ...PluginIndexActions::actions($plop, $filePath, $answers, ['middlewares'], (string) $answers['name']),
                    ];
                }

                return $baseActions;
            },
        ]);
    }
}
