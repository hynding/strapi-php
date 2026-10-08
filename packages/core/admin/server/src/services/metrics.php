<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/metrics.ts (`admin::metrics`). Telemetry is a no-op in the PHP port
 * (core's `strapi.telemetry.send()` sends nothing); the API and the queries are kept.
 */
final class Metrics
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function sendDidInviteUser(): void
    {
        $numberOfUsers = Utils::getService($this->strapi, 'user')->count();
        $numberOfRoles = Utils::getService($this->strapi, 'role')->count();
        $this->strapi->telemetry()->send('didInviteUser', [
            'groupProperties' => ['numberOfRoles' => $numberOfRoles, 'numberOfUsers' => $numberOfUsers],
        ]);
    }

    public function sendDidUpdateRolePermissions(): void
    {
        $this->strapi->telemetry()->send('didUpdateRolePermissions');
    }

    public function sendDidChangeInterfaceLanguage(): void
    {
        $languagesInUse = Utils::getService($this->strapi, 'user')->getLanguagesInUse();
        // This event is anonymous
        $this->strapi->telemetry()->send('didChangeInterfaceLanguage', ['userProperties' => ['languagesInUse' => $languagesInUse]]);
    }

    public function sendUpdateProjectInformation(Strapi $strapi): void
    {
        $numberOfActiveAdminUsers = Utils::getService($this->strapi, 'user')->count(['isActive' => true]);
        $numberOfAdminUsers = Utils::getService($this->strapi, 'user')->count();

        $strapi->telemetry()->send('didUpdateProjectInformation', [
            'groupProperties' => ['numberOfActiveAdminUsers' => $numberOfActiveAdminUsers, 'numberOfAdminUsers' => $numberOfAdminUsers],
        ]);
    }

    public function startCron(Strapi $strapi): void
    {
        $strapi->cron()->add([
            'sendProjectInformation' => [
                'task' => function () use ($strapi): void {
                    $this->sendUpdateProjectInformation($strapi);
                },
                'options' => '0 0 0 * * *',
            ],
        ]);
    }
}
