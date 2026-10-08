<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Services\CoreStore as CoreStoreService;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/providers/coreStore.ts. */
final class CoreStore extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        $strapi->get('models')->add(CoreStoreService::coreStoreModel());
        $strapi->add('coreStore', static fn (): CoreStoreService => CoreStoreService::createCoreStore($strapi->db()));
    }
}
