<?php

declare(strict_types=1);

/**
 * Port of packages/core/openapi/src/assemblers/types.ts, exported upstream as the `Assembler`
 * namespace (`Assembler.Document`, `Assembler.Path`, ...): the interfaces live together in the
 * `Strapi\Openapi\Assemblers\Assembler` namespace, as in upstream's single file.
 */

namespace Strapi\Openapi\Assemblers\Assembler;

use Strapi\Openapi\Context\Context;

interface Assembler
{
}

interface Document extends Assembler
{
    public function assemble(Context $context): void;
}

interface Path extends Assembler
{
    public function assemble(Context $context): void;
}

interface PathItem extends Assembler
{
    /** @param list<array<string, mixed>> $routes */
    public function assemble(Context $context, string $path, array $routes): void;
}

interface Operation extends Assembler
{
    /** @param array<string, mixed> $route */
    public function assemble(Context $context, array $route): void;
}
