<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops;

use Strapi\Generators\Plop;
use Strapi\Generators\Plops\Prompts\GetDestinationPrompts;
use Strapi\Generators\Plops\Utils\GetFilePath;
use Strapi\Generators\Plops\Utils\PluginIndexActions;

/** Port of src/plops/service.ts: `strapi generate service`. */
final class Service
{
    public function __invoke(Plop $plop): void
    {
        // service generator
        $plop->setGenerator('service', [
            'description' => 'Generate a service for an API',
            'prompts' => [
                [
                    'type' => 'input',
                    'name' => 'id',
                    'message' => 'Service name',
                ],
                ...GetDestinationPrompts::getDestinationPrompts('service', $plop->getDestBasePath()),
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
                        'path' => "{$filePath}/services/{{ id }}.php",
                        'templateFile' => 'templates/php/service.php.tpl',
                    ],
                ];

                if (($answers['plugin'] ?? null) !== null && $answers['plugin'] !== '') {
                    $baseActions = [
                        ...$baseActions,
                        ...PluginIndexActions::actions($plop, $filePath, $answers, ['services'], (string) $answers['id']),
                    ];
                }

                return $baseActions;
            },
        ]);
    }
}
