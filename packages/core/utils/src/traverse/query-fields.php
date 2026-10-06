<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

use Strapi\Types\Schema\Schema;

/** Port of packages/core/utils/src/traverse/query-fields.ts. */
final class QueryFields
{
    private static ?Factory $factory = null;

    /**
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param array{schema: Schema|array<string, mixed>|null, getModel: callable, path?: Path|null, parent?: ParentNode|null} $options
     */
    public static function traverse(callable $visitor, array $options, mixed $fields): mixed
    {
        return self::factory()->traverse($visitor, $options, $fields);
    }

    /**
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param array{schema: Schema|array<string, mixed>|null, getModel: callable, path?: Path|null, parent?: ParentNode|null} $options
     * @return \Closure(mixed): mixed
     */
    public static function create(callable $visitor, array $options): \Closure
    {
        return static fn (mixed $fields): mixed => self::traverse($visitor, $options, $fields);
    }

    public static function factory(): Factory
    {
        return self::$factory ??= Factory::create()
            // Intercept array of strings e.g. fields=['title', 'description']
            ->intercept(
                Factory::isStringArray(...),
                static function (callable $visitor, array $options, array $fields, \Closure $recurse): array {
                    $out = [];
                    foreach ($fields as $field) {
                        $out[] = $recurse($visitor, $options, $field);
                    }

                    return $out;
                },
            )
            // Intercept comma separated fields (as string) e.g. fields='title,description'
            ->intercept(
                static fn (mixed $value): bool => is_string($value) && str_contains($value, ','),
                static function (callable $visitor, array $options, string $fields, \Closure $recurse): array {
                    $out = [];
                    foreach (explode(',', $fields) as $field) {
                        $out[] = $recurse($visitor, $options, $field);
                    }

                    return $out;
                },
            )
            // Return wildcards as is
            ->intercept(static fn (mixed $value): bool => $value === '*', static fn (): string => '*')
            // Parse string values: each value is an attribute name; get returns the data if key === data
            ->parse('is_string', static fn (): array => [
                'transform' => static fn (string $value): string => trim($value),
                'remove' => static fn (string $key, ?string $data): ?string => $data === $key ? null : $data,
                'set' => static fn (string $key, mixed $value, ?string $data): ?string => $data,
                'keys' => static fn (?string $data): array => [$data ?? ''],
                'get' => static fn (string $key, ?string $data): ?string => $key === $data ? $data : null,
            ]);
    }
}
