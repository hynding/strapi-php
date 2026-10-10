<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

use Strapi\Utils\Zod\ParsePayload;
use Strapi\Utils\Zod\Undefined;
use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/**
 * A Zod schema built by a test and handed to the instance (`route.request.query = { search: z.string() }`,
 * `contentAPI.addInputParams({ ... })`, see lib/bridge.js): it parses in the test process, with the
 * test's own zod (`{ "$zod": { type, safeParse, optionalIn, optionalOut, jsonSchema } }`, `safeParse`
 * a {@see Callback}), so transforms, defaults and refinements behave as upstream's. An object schema
 * arrives as its shape (`{ type: "object", shape }`) and becomes a PHP `z.object()` of remote fields,
 * which code composing route schemas can extend.
 */
final class RemoteZod extends ZodType
{
    /** @param array<string, mixed> $jsonSchema */
    private function __construct(
        private readonly string $zodType,
        private readonly Callback $safeParse,
        private readonly bool $optionalIn,
        private readonly bool $optionalOut,
        private readonly array $jsonSchema,
    ) {
    }

    /** @param array<string, mixed> $zod the `$zod` payload, references resolved */
    public static function fromTest(array $zod): ZodType
    {
        if (($zod['type'] ?? null) === 'object' && is_array($zod['shape'] ?? null)) {
            $shape = [];
            foreach ($zod['shape'] as $key => $field) {
                $shape[(string) $key] = $field instanceof ZodType ? $field : z::unknown();
            }

            return z::object($shape);
        }

        if (!($zod['safeParse'] ?? null) instanceof Callback) {
            return z::unknown();
        }

        return new self(
            (string) ($zod['type'] ?? 'custom'),
            $zod['safeParse'],
            ($zod['optionalIn'] ?? false) === true,
            ($zod['optionalOut'] ?? false) === true,
            is_array($zod['jsonSchema'] ?? null) ? $zod['jsonSchema'] : [],
        );
    }

    public function type(): string
    {
        return $this->zodType;
    }

    public function isOptionalIn(): bool
    {
        return $this->optionalIn;
    }

    public function isOptionalOut(): bool
    {
        return $this->optionalOut;
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $undefined = $payload->value === Undefined::Value;
        $result = ($this->safeParse)($undefined ? null : $payload->value, $undefined);
        if (!is_array($result)) {
            return $this->issue($payload, ['code' => 'custom', 'message' => 'api-tests: no answer from the test\'s zod schema']);
        }

        if (($result['success'] ?? false) === true) {
            $payload->value = ($result['undefined'] ?? false) === true ? Undefined::Value : ($result['data'] ?? null);

            return $payload;
        }

        foreach (is_array($result['issues'] ?? null) ? $result['issues'] : [] as $issue) {
            if (is_array($issue)) {
                $payload->issues[] = [...$issue, 'input' => $payload->value];
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $params */
    public function toJSONSchema(array $params = []): array
    {
        return $this->jsonSchema;
    }
}
