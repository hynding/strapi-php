<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Scalars;

use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\CustomScalarType;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\ParseType;

/**
 * Port of server/src/services/internals/scalars/time.ts: a GraphQL scalar used to store Time
 * (HH:mm:ss.SSS) values.
 */
final class Time
{
    private static ?CustomScalarType $type = null;

    public static function type(): CustomScalarType
    {
        return self::$type ??= new CustomScalarType([
            'name' => 'Time',

            'description' => 'A time string with format HH:mm:ss.SSS',

            'serialize' => static fn (mixed $value): mixed => ParseType::parseType(['type' => 'time', 'value' => $value]),

            'parseValue' => static fn (mixed $value): mixed => ParseType::parseType(['type' => 'time', 'value' => $value]),

            'parseLiteral' => static function (Node $ast, ?array $variables = null): mixed {
                if (!$ast instanceof StringValueNode) {
                    throw new ValidationError('Time cannot represent non string type');
                }

                $value = $ast->value;

                return ParseType::parseType(['type' => 'time', 'value' => $value]);
            },
        ]);
    }
}
