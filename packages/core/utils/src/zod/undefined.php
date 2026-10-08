<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * JavaScript's `undefined`. PHP has a single `null`, so zod's distinction between a missing
 * value (`undefined`, accepted by `.optional()`) and `null` (accepted by `.nullable()`) needs a
 * sentinel: a missing object key is parsed as `Undefined::Value`, and `$schema->parse()` called
 * without an argument parses `undefined`.
 *
 * Callbacks (`refine`, `superRefine`, `transform`, `preprocess`, `custom`) receive
 * `Undefined::Value` where zod would pass `undefined`. Parse results never contain it: an
 * undefined object property is left out of the output, an undefined array item becomes `null`
 * and an undefined top-level result is returned as `null`.
 */
enum Undefined
{
    case Value;
}
