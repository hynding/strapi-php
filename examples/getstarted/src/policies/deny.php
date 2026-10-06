<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Utils\Policy\PolicyContext;

/**
 * `deny` policy: always refuses (used by the integration tests to check the 403 PolicyError envelope).
 */
return static fn (PolicyContext $policyCtx, array $config, Strapi $strapi): bool => false;
