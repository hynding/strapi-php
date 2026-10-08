<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.ZodError`, thrown by `parse()` and returned by `safeParse()`. `$issues` is a list of
 * finalized zod v4 issues: `['code' => ..., 'path' => list<string|int>, 'message' => ...]` plus
 * the code-specific fields (`expected`, `minimum`, `maximum`, `inclusive`, `origin`, `format`,
 * `pattern`, `keys`, `values`, `errors`, ...), in zod's key order. As in zod, the exception
 * message is the issues serialized as indented JSON.
 *
 * @phpstan-type ZodIssue array<string, mixed>
 */
class ZodError extends \RuntimeException
{
    public string $name = 'ZodError';

    /** @param list<array<string, mixed>> $issues */
    public function __construct(public readonly array $issues)
    {
        parent::__construct((string) json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * `z.flattenError(error)` / `error.flatten()`: root issue messages in `formErrors`, messages of
     * issues with a path grouped by their first path segment in `fieldErrors`.
     *
     * @return array{formErrors: list<string>, fieldErrors: array<string, list<string>>}
     */
    public function flatten(): array
    {
        $formErrors = [];
        $fieldErrors = [];
        foreach ($this->issues as $issue) {
            /** @var list<string|int> $path */
            $path = $issue['path'] ?? [];
            $message = (string) ($issue['message'] ?? '');
            if ($path === []) {
                $formErrors[] = $message;
            } else {
                $fieldErrors[(string) $path[0]][] = $message;
            }
        }

        return ['formErrors' => $formErrors, 'fieldErrors' => $fieldErrors];
    }
}
