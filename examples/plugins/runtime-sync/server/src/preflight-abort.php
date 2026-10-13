<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync;

/**
 * Mirrors preflight-abort.js: thrown by the preflight service to stop a boot started with
 * `STRAPI_RUNTIME_SYNC_PREFLIGHT=1`, carrying the report (`strapi-runtime-sync db:check`).
 *
 * @phpstan-type Report array<string, mixed>
 */
final class PreflightAbort extends \RuntimeException
{
    /** @param Report $report */
    public function __construct(public readonly array $report)
    {
        parent::__construct('runtime-sync preflight: boot aborted on request, nothing was written (STRAPI_RUNTIME_SYNC_PREFLIGHT)');
    }
}
