<?php

declare(strict_types=1);

namespace Strapi\Openapi\Context\Factories;

use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Registries\RegistriesFactory;
use Strapi\Openapi\Utils\Timer\TimerFactory;

/**
 * Port of packages/core/openapi/src/context/factories/document.ts.
 *
 * @phpstan-import-type PartialContext from Context
 */
final class DocumentContextFactory extends AbstractContextFactory
{
    public function __construct(?RegistriesFactory $registriesFactory = null, ?TimerFactory $timerFactory = null)
    {
        parent::__construct($registriesFactory ?? new RegistriesFactory(), $timerFactory ?? new TimerFactory());
    }

    /**
     * @param PartialContext $context
     * @param array<string, mixed> $defaultValue ignored: the output starts empty (`{}`)
     */
    public function create(array $context, array $defaultValue = []): Context
    {
        return parent::create($context, []);
    }
}
