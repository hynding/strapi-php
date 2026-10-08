<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops;

use Strapi\Generators\Plop;
use Strapi\Generators\Plops\Utils\GetFormattedDate;
use Strapi\Generators\Plops\Utils\ValidateFileNameInput;

/**
 * Port of src/plops/migration.ts: `strapi generate migration`.
 *
 * Upstream writes `<cwd>/database/migrations/<timestamp>.<name>.(js|ts)`; here the migration is
 * `<dir>/database/migrations/<timestamp>.<name>.php` (`dir` being the project root `generate()`
 * runs on, the cwd from the CLI), returning `['up' => fn (Connection $trx, Database $db) => ...]`.
 */
final class Migration
{
    public function __invoke(Plop $plop): void
    {
        // Migration generator
        $plop->setGenerator('migration', [
            'description' => 'Generate a migration',
            'prompts' => [
                [
                    'type' => 'input',
                    'name' => 'name',
                    'message' => 'Migration name',
                    'validate' => static fn (mixed $input): bool|string => ValidateFileNameInput::validateFileNameInput($input),
                ],
            ],
            'actions' => static function (): array {
                $timestamp = GetFormattedDate::getFormattedDate();

                return [
                    [
                        'type' => 'add',
                        // paths are relative to src/
                        'path' => "../database/migrations/{$timestamp}.{{ name }}.php",
                        'templateFile' => 'templates/php/migration.php.tpl',
                    ],
                ];
            },
        ]);
    }
}
