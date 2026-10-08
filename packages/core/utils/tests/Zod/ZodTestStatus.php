<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests\Zod;

/** A backed enum for `z::enum(SomeEnum::class)` tests. */
enum ZodTestStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
