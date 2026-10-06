<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Utils;

/** Port of utils/ordered-parallel.ts — synchronous: run every task, throw the first error in order. */
final class OrderedParallel
{
    /**
     * @param list<callable(): mixed> $tasks
     * @return list<mixed>
     */
    public static function runParallelWithOrderedErrors(array $tasks): array
    {
        $results = [];
        $firstError = null;
        foreach ($tasks as $task) {
            try {
                $results[] = $task();
            } catch (\Throwable $e) {
                $firstError ??= $e;
                $results[] = null;
            }
        }
        if ($firstError !== null) {
            throw $firstError;
        }

        return $results;
    }
}
