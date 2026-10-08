<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops;

use Strapi\Generators\Plop;
use Strapi\Generators\Plops\Prompts\GetDestinationPrompts;
use Strapi\Generators\Plops\Utils\GetFilePath;
use Strapi\Generators\Plops\Utils\PluginIndexActions;
use Strapi\Generators\Plops\Utils\ValidateInput;

/** Port of src/plops/policy.ts: `strapi generate policy`. */
final class Policy
{
    public function __invoke(Plop $plop): void
    {
        // policy generator
        $plop->setGenerator('policy', [
            'description' => 'Generate a policy for an API',
            'prompts' => [
                [
                    'type' => 'input',
                    'name' => 'id',
                    'message' => 'Policy name',
                    'validate' => static fn (mixed $input): bool|string => ValidateInput::validateInput($input),
                ],
                ...GetDestinationPrompts::getDestinationPrompts('policy', $plop->getDestBasePath(), ['rootFolder' => true]),
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
                        'path' => "{$filePath}/policies/{{ id }}.php",
                        'templateFile' => 'templates/php/policy.php.tpl',
                    ],
                ];

                if (($answers['plugin'] ?? null) !== null && $answers['plugin'] !== '') {
                    $baseActions = [
                        ...$baseActions,
                        ...PluginIndexActions::actions($plop, $filePath, $answers, ['policies'], (string) $answers['id']),
                    ];
                }

                return $baseActions;
            },
        ]);
    }
}
