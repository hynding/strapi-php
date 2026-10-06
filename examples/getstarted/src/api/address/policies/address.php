<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Utils\Policy\PolicyContext;

return static fn (PolicyContext $policyCtx, array $config, Strapi $strapi): bool => true;
