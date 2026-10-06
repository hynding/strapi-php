<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/services/custom-fields.ts: `strapi.customFields.register(...)`. */
final class CustomFields
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createCustomFields(Strapi $strapi): self
    {
        return new self($strapi);
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $customField */
    public function register(array $customField): void
    {
        $this->strapi->get('custom-fields')->add($customField);
    }
}
