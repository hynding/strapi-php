<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

/** Port of packages/core/core/src/utils/cron.ts (`shiftCronExpression`). */
final class Cron
{
    private const COMPONENTS = [
        ['limit' => 60, 'zeroBasedIndices' => true, 'fn' => 's'],
        ['limit' => 60, 'zeroBasedIndices' => true, 'fn' => 'i'],
        ['limit' => 24, 'zeroBasedIndices' => true, 'fn' => 'G'],
        ['limit' => 31, 'zeroBasedIndices' => false, 'fn' => 'j'],
        ['limit' => 12, 'zeroBasedIndices' => false, 'fn' => 'n'],
        ['limit' => 7, 'zeroBasedIndices' => true, 'fn' => 'w'],
    ];

    private static function shift(string $component, int $index, \DateTimeInterface $date): string
    {
        if ($component === '*') {
            return '*';
        }

        ['limit' => $limit, 'zeroBasedIndices' => $zeroBasedIndices, 'fn' => $fn] = self::COMPONENTS[$index];
        $offset = $zeroBasedIndices ? 0 : 1;
        // JS getMonth() is zero based while PHP `n` is 1-based; getDate() is 1-based like `j`
        $currentValue = (int) $date->format($fn) - ($fn === 'n' ? 1 : 0);

        if (preg_match('/^\d+$/', $component) === 1) {
            return (string) ((((int) $component) + $currentValue) % $limit + $offset);
        }

        if (preg_match('/^\*\/\d+$/', $component) === 1) {
            $step = (int) explode('/', $component)[1];
            $frequency = intdiv($limit, $step);
            $list = [];
            for ($i = 0; $i < $frequency; $i++) {
                $list[] = ($i * $step + $currentValue) % $limit + $offset;
            }
            sort($list);

            return implode(',', $list);
        }

        // Unsupported syntax
        return $component;
    }

    /** Simulate an interval by shifting a cron expression using the specified date. */
    public static function shiftCronExpression(string $rule, ?\DateTimeInterface $date = null): string
    {
        $date ??= new \DateTimeImmutable();
        $components = array_values(array_filter(explode(' ', trim($rule)), static fn (string $c): bool => $c !== ''));
        $secondsIncluded = count($components) === 6;

        $out = [];
        foreach ($components as $index => $component) {
            $out[] = self::shift($component, $secondsIncluded ? $index : $index + 1, $date);
        }

        return implode(' ', $out);
    }
}
