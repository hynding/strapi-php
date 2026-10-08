<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers\Transfer;

use Strapi\Core\Registries\ActionMap;
use Strapi\Core\Strapi;

/**
 * Port of server/src/controllers/transfer/index.ts: `admin::transfer` merges the runner and token
 * controllers, their action names prefixed (`runner-push`, `token-create`, …).
 */
final class Transfer
{
    /** @return array<string, callable> */
    public static function prefixActionsName(string $prefix, object $controller): array
    {
        $actions = [];
        foreach (ActionMap::actionNames($controller) as $name) {
            $actions["{$prefix}-{$name}"] = ActionMap::action($controller, $name);
        }

        return $actions;
    }

    public static function create(Strapi $strapi): ActionMap
    {
        return new ActionMap([
            ...self::prefixActionsName('runner', new Runner()),
            ...self::prefixActionsName('token', new Token($strapi)),
        ]);
    }
}
