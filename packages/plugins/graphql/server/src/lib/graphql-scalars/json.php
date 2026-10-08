<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\GraphqlScalars;

use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NullValueNode;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\AST\VariableNode;
use GraphQL\Type\Definition\CustomScalarType;

/** graphql-scalars 1.22 `GraphQLJSON` (json/JSON.ts, json/utils.ts). */
final class Json
{
    private static ?CustomScalarType $type = null;

    public static function type(): CustomScalarType
    {
        return self::$type ??= new CustomScalarType([
            'name' => 'JSON',
            'description' => 'The `JSON` scalar type represents JSON values as specified by [ECMA-404](http://www.ecma-international.org/publications/files/ECMA-ST/ECMA-404.pdf).',
            'serialize' => static fn (mixed $value): mixed => $value,
            'parseValue' => static fn (mixed $value): mixed => $value,
            'parseLiteral' => static fn (Node $ast, ?array $variables = null): mixed => self::parseLiteral($ast, $variables),
            'specifiedByURL' => 'http://www.ecma-international.org/publications/files/ECMA-ST/ECMA-404.pdf',
        ]);
    }

    /** @param array<string, mixed>|null $variables */
    public static function parseLiteral(Node $ast, ?array $variables = null): mixed
    {
        return match (true) {
            $ast instanceof StringValueNode, $ast instanceof BooleanValueNode => $ast->value,
            $ast instanceof IntValueNode, $ast instanceof FloatValueNode => self::number($ast->value),
            $ast instanceof ObjectValueNode => self::parseObject($ast, $variables),
            $ast instanceof ListValueNode => array_map(static fn (Node $n): mixed => self::parseLiteral($n, $variables), iterator_to_array($ast->values)),
            $ast instanceof NullValueNode => null,
            $ast instanceof VariableNode => $variables[$ast->name->value] ?? null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed>|null $variables
     * @return array<string, mixed>
     */
    private static function parseObject(ObjectValueNode $ast, ?array $variables): array
    {
        $value = [];
        foreach ($ast->fields as $field) {
            $value[$field->name->value] = self::parseLiteral($field->value, $variables);
        }

        return $value;
    }

    /** `parseFloat()`: an integer when it has no fraction */
    private static function number(string $value): int|float
    {
        $float = (float) $value;

        return floor($float) === $float && abs($float) <= PHP_INT_MAX ? (int) $float : $float;
    }
}
