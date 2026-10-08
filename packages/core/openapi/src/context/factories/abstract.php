<?php

declare(strict_types=1);

namespace Strapi\Openapi\Context\Factories;

use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Context\ContextOutput;
use Strapi\Openapi\Registries\RegistriesFactory;
use Strapi\Openapi\Utils\Timer\TimerFactory;

/**
 * Port of packages/core/openapi/src/context/factories/abstract.ts.
 *
 * @phpstan-import-type PartialContext from Context
 */
abstract class AbstractContextFactory
{
    protected function __construct(
        private readonly RegistriesFactory $registriesFactory,
        private readonly TimerFactory $timerFactory,
    ) {
    }

    /**
     * @param PartialContext $context
     * @param array<string, mixed> $defaultValue
     */
    public function create(array $context, array $defaultValue = []): Context
    {
        // Allow overrides to share registries and timer in case the context is used in sub-assemblers
        $timer = $context['timer'] ?? $this->timerFactory->create();
        $registries = $context['registries'] ?? $this->registriesFactory->createAll();

        // Default output initialized with the given default value
        $output = $this->createDefaultOutput($defaultValue);

        return new Context($context['strapi'], $context['routes'], $timer, $registries, $output);
    }

    /** @param array<string, mixed> $data */
    protected function createDefaultOutput(array $data): ContextOutput
    {
        return new ContextOutput($data, ['time' => ['startTime' => 0, 'endTime' => 0, 'elapsedTime' => 0]]);
    }
}
