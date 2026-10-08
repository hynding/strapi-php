<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Utils;

/**
 * Port of server/src/services/utils/playground.ts: stores the state of the Apollo landing page
 * (playground), read by `strapi::security` to add its CSP exceptions.
 */
final class Playground
{
    private bool $enabled = false;

    public function setEnabled(bool $val): void
    {
        $this->enabled = $val;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
