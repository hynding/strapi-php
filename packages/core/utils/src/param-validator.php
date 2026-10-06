<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * The slice of a Zod schema the content API needs for extra route params: `safeParse(value)`.
 * Returns `[true, parsed]` on success and `[false, message]` on failure.
 */
interface ParamValidator
{
    /** @return array{0: bool, 1: mixed} */
    public function safeParse(mixed $value): array;
}
