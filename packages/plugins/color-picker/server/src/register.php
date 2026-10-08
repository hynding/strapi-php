<?php

declare(strict_types=1);

namespace Strapi\Plugin\ColorPicker;

use Strapi\Core\Strapi;

/** Port of server/src/register.ts: registers the `plugin::color-picker.color` custom field. */
final class Register
{
    public function __invoke(Strapi $strapi): void
    {
        $strapi->customFields()->register([
            'name' => 'color',
            'plugin' => 'color-picker',
            'type' => 'string',
        ]);
    }
}
