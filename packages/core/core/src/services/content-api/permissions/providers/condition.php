<?php

declare(strict_types=1);

namespace Strapi\Core\Services\ContentApi\Permissions\Providers;

use Strapi\Core\Strapi;
use Strapi\Utils\ProviderFactory;

/**
 * Port of packages/core/core/src/services/content-api/permissions/providers/condition.ts.
 * `registerCondition(['name' => ..., 'handler' => ...])` is upstream's `register(condition)`.
 */
/** @extends ProviderFactory<mixed> */
final class Condition extends ProviderFactory
{
    /** @param array{throwOnDuplicates?: bool} $options */
    public function __construct(private readonly Strapi $strapi, array $options = [])
    {
        parent::__construct($options);
    }

    /** @param array{throwOnDuplicates?: bool} $options */
    public static function createConditionProvider(Strapi $strapi, array $options = []): self
    {
        return new self($strapi, $options);
    }

    /** @param array{name: string, handler: callable} $condition */
    public function registerCondition(array $condition): static
    {
        if ($this->strapi->isLoaded()) {
            throw new \RuntimeException("You can't register new conditions outside the bootstrap function.");
        }

        return parent::register($condition['name'], $condition);
    }
}
