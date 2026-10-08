<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Prompts;

use Strapi\Generators\Plops\Utils\Pluralize;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of src/plops/prompts/ct-names-prompts.ts.
 *
 * @phpstan-import-type Question from \Strapi\Generators\Plop
 */
final class CtNamesPrompts
{
    /** @return list<Question> */
    public static function questions(): array
    {
        return [
            [
                'type' => 'input',
                'name' => 'displayName',
                'message' => 'Content type display name',
                'validate' => static fn (mixed $input): bool => is_string($input) && $input !== '',
            ],
            [
                'type' => 'input',
                'name' => 'singularName',
                'message' => 'Content type singular name',
                'default' => static fn (array $answers): string => Strings::slugify((string) ($answers['displayName'] ?? '')),
                'validate' => static function (mixed $input): bool|string {
                    if (!is_string($input) || !Strings::isKebabCase($input)) {
                        return 'Value must be in kebab-case';
                    }

                    return true;
                },
            ],
            [
                'type' => 'input',
                'name' => 'pluralName',
                'message' => 'Content type plural name',
                'default' => static fn (array $answers): string => Pluralize::plural((string) ($answers['singularName'] ?? '')),
                'validate' => static function (mixed $input, array $answers): bool|string {
                    if (($answers['singularName'] ?? null) === $input) {
                        return 'Singular and plural names cannot be the same';
                    }

                    if (!is_string($input) || !Strings::isKebabCase($input)) {
                        return 'Value must be in kebab-case';
                    }

                    return true;
                },
            ],
        ];
    }
}
