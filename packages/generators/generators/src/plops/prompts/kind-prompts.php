<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Prompts;

use Strapi\Generators\Plops\Utils\ValidateInput;

/**
 * Port of src/plops/prompts/kind-prompts.ts.
 *
 * @phpstan-import-type Question from \Strapi\Generators\Plop
 */
final class KindPrompts
{
    /** @return list<Question> */
    public static function questions(): array
    {
        return [
            [
                'type' => 'list',
                'name' => 'kind',
                'message' => 'Please choose the model type',
                'default' => 'collectionType',
                'choices' => [
                    ['name' => 'Collection Type', 'value' => 'collectionType'],
                    ['name' => 'Single Type', 'value' => 'singleType'],
                ],
                'validate' => static fn (mixed $input): bool|string => ValidateInput::validateInput($input),
            ],
        ];
    }
}
