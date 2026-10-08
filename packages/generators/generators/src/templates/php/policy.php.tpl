<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Utils\Policy\PolicyContext;

/**
 * `{{ id }}` policy
 */
return static function (PolicyContext $policyCtx, array $config, Strapi $strapi): bool {
    // Add your own logic here.
    $strapi->log()->info('In {{ id }} policy.');

    $canDoSomething = true;

    if ($canDoSomething) {
        return true;
    }

    return false;
};
