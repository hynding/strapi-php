<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/**
 * An MCP tool definition registered by a test (`strapi.ai.mcp.registerTool({...})`, see
 * lib/bridge.js): its Zod schemas arrive as JSON Schema and are rebuilt as PHP Zod (the subset
 * tests use: objects, strings, numbers, booleans, arrays, enums, defaults), its handler is a
 * {@see Callback} into the test process.
 */
final class McpDefinition
{
    /**
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    public static function fromTest(array $tool): array
    {
        $handler = $tool['handler'];
        $input = is_array($tool['inputJsonSchema'] ?? null) ? self::toZod($tool['inputJsonSchema']) : null;
        $output = self::toZod(is_array($tool['outputJsonSchema'] ?? null) ? $tool['outputJsonSchema'] : ['type' => 'object']);
        unset($tool['handler'], $tool['inputJsonSchema'], $tool['outputJsonSchema']);

        return [
            ...$tool,
            ...($input !== null ? ['resolveInputSchema' => static fn (): ZodType => $input] : []),
            'resolveOutputSchema' => static fn (): ZodType => $output,
            'createHandler' => static fn (): \Closure => static function (array $params) use ($handler): mixed {
                $result = is_callable($handler) ? $handler($params) : null;

                return is_array($result) ? $result : ['content' => []];
            },
        ];
    }

    /** @param array<string, mixed> $schema */
    public static function toZod(array $schema): ZodType
    {
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $type = z::enum(array_values(array_map(static fn (mixed $v): string => (string) $v, $schema['enum'])));
        } else {
            $type = match ($schema['type'] ?? null) {
                'string' => z::string(),
                'number' => z::number(),
                'integer' => z::number()->int(),
                'boolean' => z::boolean(),
                'array' => z::array(is_array($schema['items'] ?? null) ? self::toZod($schema['items']) : z::unknown()),
                'object' => self::object($schema),
                default => z::unknown(),
            };
        }

        return array_key_exists('default', $schema) ? $type->default($schema['default']) : $type;
    }

    /** @param array<string, mixed> $schema */
    private static function object(array $schema): ZodType
    {
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $shape = [];
        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $key => $prop) {
            $field = self::toZod(is_array($prop) ? $prop : []);
            $shape[(string) $key] = in_array($key, $required, true) || array_key_exists('default', (array) $prop) ? $field : $field->optional();
        }

        return z::object($shape);
    }
}
