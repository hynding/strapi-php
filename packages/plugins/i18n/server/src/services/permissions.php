<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Services\Permissions\Actions;
use Strapi\Plugin\I18n\Services\Permissions\Engine;
use Strapi\Plugin\I18n\Services\Permissions\SectionsBuilder;

/** Port of server/src/services/permissions.ts. */
final class Permissions
{
    public readonly Actions $actions;

    public readonly SectionsBuilder $sectionsBuilder;

    public readonly Engine $engine;

    public function __construct(Strapi $strapi)
    {
        $this->actions = new Actions($strapi);
        $this->sectionsBuilder = new SectionsBuilder($strapi);
        $this->engine = new Engine($strapi);
    }
}
