<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Prompts;

use Strapi\Generators\Inquirer;
use Strapi\Generators\Plops\Utils\ValidateAttributeInput;

/**
 * Port of src/plops/prompts/get-attributes-prompts.ts.
 *
 * @phpstan-type AttributeAnswer array{attributeName: string, attributeType: string, enum?: string, multiple?: bool, addAttributes?: bool}
 */
final class GetAttributesPrompts
{
    public const array DEFAULT_TYPES = [
        // advanced types
        'media',

        // scalar types
        'string',
        'text',
        'richtext',
        'json',
        'enumeration',
        'password',
        'email',
        'integer',
        'biginteger',
        'float',
        'decimal',
        'date',
        'time',
        'datetime',
        'timestamp',
        'boolean',
    ];

    /** @return list<AttributeAnswer> */
    public static function getAttributesPrompts(Inquirer $inquirer): array
    {
        ['addAttributes' => $addAttributes] = $inquirer->prompt([
            [
                'type' => 'confirm',
                'name' => 'addAttributes',
                'message' => 'Do you want to add attributes?',
            ],
        ]) + ['addAttributes' => false];

        /** @var list<AttributeAnswer> $attributes */
        $attributes = [];

        $createNewAttributes = static function (Inquirer $inquirer) use (&$createNewAttributes, &$attributes): void {
            $answers = $inquirer->prompt([
                [
                    'type' => 'input',
                    'name' => 'attributeName',
                    'message' => 'Name of attribute',
                    'validate' => static fn (mixed $input): bool|string => ValidateAttributeInput::validateAttributeInput($input),
                ],
                [
                    'type' => 'list',
                    'name' => 'attributeType',
                    'message' => 'What type of attribute',
                    'choices' => array_map(static fn (string $type): array => ['name' => $type, 'value' => $type], self::DEFAULT_TYPES),
                ],
                [
                    'when' => static fn (array $answers): bool => ($answers['attributeType'] ?? null) === 'enumeration',
                    'type' => 'input',
                    'name' => 'enum',
                    'message' => 'Add values separated by a comma',
                ],
                [
                    'when' => static fn (array $answers): bool => ($answers['attributeType'] ?? null) === 'media',
                    'type' => 'list',
                    'name' => 'multiple',
                    'message' => 'Choose media type',
                    'choices' => [
                        ['name' => 'Multiple', 'value' => true],
                        ['name' => 'Single', 'value' => false],
                    ],
                ],
                [
                    'type' => 'confirm',
                    'name' => 'addAttributes',
                    'message' => 'Do you want to add another attribute?',
                ],
            ]);

            /** @var AttributeAnswer $answers */
            $attributes[] = $answers;

            if (!($answers['addAttributes'] ?? false)) {
                return;
            }

            $createNewAttributes($inquirer);
        };

        if ($addAttributes) {
            $createNewAttributes($inquirer);
        } else {
            $inquirer->warn("You won't be able to manage entries from the admin, you can still add attributes later from the content type builder.");
        }

        return $attributes;
    }
}
