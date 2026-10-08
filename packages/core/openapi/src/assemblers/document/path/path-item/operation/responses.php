<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Utils\Zod;
use Strapi\Utils\Zod\ZodType;

/** Port of packages/core/openapi/src/assemblers/document/path/path-item/operation/responses.ts. */
final class OperationResponsesAssembler implements Assembler\Operation
{
    /** @return array<int, array{description: string}> */
    private function errors(): array
    {
        return [
            400 => ['description' => 'Bad request'],
            401 => ['description' => 'Unauthorized'],
            403 => ['description' => 'Forbidden'],
            404 => ['description' => 'Not found'],
            500 => ['description' => 'Internal server error'],
        ];
    }

    public function assemble(Context $context, array $route): void
    {
        $output = $context->output;

        $responses = is_array($output->data['responses'] ?? null) ? $output->data['responses'] : [];

        // Register common error responses first to allow manual overrides
        foreach ($this->errors() as $errorCode => $response) {
            $responses[$errorCode] = $response;
        }

        $response = $route['response'] ?? null;
        if ($response instanceof ZodType) {
            $schema = Zod::zodToOpenAPI($response, $context->strapi->contentAPISchemaRegistry(), [
                'extractedComponentSchemas' => $context->registries->extractedComponentSchemas,
            ]);

            $responses[200] = [
                'description' => 'OK',
                'content' => ['application/json' => ['schema' => $schema]],
            ];
        }

        $output->data['responses'] = self::jsKeyOrder($responses);
    }

    /**
     * JS objects enumerate integer-like keys first, in ascending order, then the other keys in
     * insertion order.
     *
     * @param array<array-key, mixed> $object
     *
     * @return array<array-key, mixed>
     */
    private static function jsKeyOrder(array $object): array
    {
        $integers = array_filter($object, static fn (int|string $key): bool => is_int($key), ARRAY_FILTER_USE_KEY);
        ksort($integers);

        return $integers + array_filter($object, static fn (int|string $key): bool => !is_int($key), ARRAY_FILTER_USE_KEY);
    }
}
