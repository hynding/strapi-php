<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services;

use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaBuilder;

/**
 * Port of server/src/services/schema-mutation.ts.
 *
 * `$builder` is anything with `rollback()` and `$apiHandler` anything with `rollback($uid)`,
 * `clearGenerated($apiName)` and `finalize($uid)` (upstream types them structurally).
 */
final class SchemaMutation
{
    /**
     * @param array{apiHandler: ApiHandler|object, backedUpApiUids?: list<string>} $options
     * @return list<\Throwable>
     */
    public static function finalizeSchemaMutation(array $options): array
    {
        $apiHandler = $options['apiHandler'];
        $errors = [];

        foreach ($options['backedUpApiUids'] ?? [] as $uid) {
            try {
                /** @phpstan-ignore method.notFound */
                $apiHandler->finalize($uid);
            } catch (\Throwable $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Restores the filesystem artifacts touched before content-structure reconciliation.
     * Folder reconciliation is deliberately the final mutation, so groups.json never needs a
     * compensating write when an earlier schema or API operation fails.
     *
     * @param array{builder: SchemaBuilder|object, apiHandler: ApiHandler|object, backedUpApiUids?: list<string>, generatedApiNames?: list<string>, schemaAlreadyRolledBack?: bool} $options
     */
    public static function rollbackSchemaMutation(array $options): void
    {
        $builder = $options['builder'];
        $apiHandler = $options['apiHandler'];
        $errors = [];
        $attempt = static function (\Closure $operation) use (&$errors): void {
            try {
                $operation();
            } catch (\Throwable $error) {
                $errors[] = $error;
            }
        };

        // A generator can fail before a schema directory exists, so remove its partial API before
        // asking the schema handler to roll back. Every action is attempted even if an earlier one
        // fails, ensuring compensation is best-effort rather than short-circuiting.
        foreach ($options['generatedApiNames'] ?? [] as $apiName) {
            /** @phpstan-ignore method.notFound */
            $attempt(static fn () => $apiHandler->clearGenerated($apiName));
        }

        if (!($options['schemaAlreadyRolledBack'] ?? false)) {
            /** @phpstan-ignore method.notFound */
            $attempt(static fn () => $builder->rollback());
        }

        foreach ($options['backedUpApiUids'] ?? [] as $uid) {
            /** @phpstan-ignore method.notFound */
            $attempt(static fn () => $apiHandler->rollback($uid));
        }

        if ($errors !== []) {
            throw $errors[0];
        }
    }
}
