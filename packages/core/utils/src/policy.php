<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Policy\PolicyContext;
use Strapi\Utils\Policy\PolicyDefinition;

/** Port of packages/core/utils/src/policy.ts. */
final class Policy
{
    /**
     * @param array{name?: string, validator?: callable(mixed): void, handler: callable} $options
     */
    public static function createPolicy(array $options): PolicyDefinition
    {
        $name = $options['name'] ?? 'unnamed';
        $validator = $options['validator'] ?? null;

        $wrappedValidator = static function (mixed $config) use ($validator, $name): void {
            if ($validator !== null) {
                try {
                    $validator($config);
                } catch (\Throwable) {
                    throw new \InvalidArgumentException("Invalid config passed to \"{$name}\" policy.");
                }
            }
        };

        return new PolicyDefinition($name, $wrappedValidator, $options['handler']);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function createPolicyContext(string $type, array $ctx): PolicyContext
    {
        return new PolicyContext($type, $ctx);
    }
}
