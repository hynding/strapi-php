<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Prompts;

/**
 * Port of src/plops/prompts/bootstrap-api-prompts.ts.
 *
 * @phpstan-import-type Question from \Strapi\Generators\Plop
 */
final class BootstrapApiPrompts
{
    /** @return list<Question> */
    public static function questions(): array
    {
        return [
            [
                'type' => 'confirm',
                'name' => 'bootstrapApi',
                'default' => true,
                'message' => 'Bootstrap API related files?',
            ],
        ];
    }
}
