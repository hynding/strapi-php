<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Services\Cron as CronService;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/providers/cron.ts. */
final class Cron extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        $strapi->add('cron', static fn (): CronService => CronService::createCronService($strapi));
    }

    public function bootstrap(Strapi $strapi): void
    {
        if ($strapi->config()->get('server.cron.enabled', true)) {
            $cronTasks = $strapi->config()->get('server.cron.tasks', []);
            $strapi->get('cron')->add(is_array($cronTasks) ? $cronTasks : []);
        }

        $strapi->get('cron')->start();
    }

    public function destroy(Strapi $strapi): void
    {
        $strapi->get('cron')->destroy();
    }
}
