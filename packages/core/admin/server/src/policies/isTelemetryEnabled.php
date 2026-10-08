<?php

declare(strict_types=1);

// Port of server/src/policies/isTelemetryEnabled.ts
use Strapi\Core\Strapi;
use Strapi\Utils\Policy;
use Strapi\Utils\Policy\PolicyContext;

/**
 * This policy is used for routes dealing with telemetry and analytics content.
 * It will fails when the telemetry has been disabled on the server.
 */
return Policy::createPolicy([
    'name' => 'admin::isTelemetryEnabled',
    'handler' => static function (PolicyContext $ctx, mixed $config, Strapi $strapi): ?bool {
        if ($strapi->telemetry()->isDisabled()) {
            return false;
        }

        return null;
    },
]);
