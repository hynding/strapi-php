<?php

declare(strict_types=1);

namespace Strapi\Types\Core;

/**
 * A policy is `callable(PolicyContext $ctx, array $config, Strapi $strapi): bool|null`.
 * Returning false (or throwing) denies the request. Mirrors Core.Policy.
 */
interface Policy
{
    /** @param array<string, mixed> $config */
    public function __invoke(Context $ctx, array $config, Strapi $strapi): ?bool;
}
