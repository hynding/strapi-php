<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use StrapiPlugin\RuntimeSync\Services\Status as StatusService;

/** Mirrors controllers/status.js. */
final readonly class Status
{
    public function __construct(private Strapi $strapi)
    {
    }

    /**
     * GET /runtime-sync/status (admin): this runtime, the database preflight of this boot, the
     * boots recorded by both runtimes, whether they agree, and the project's file pairs.
     *
     * @return array{data: array<string, mixed>}
     */
    public function find(Context $ctx): array
    {
        $service = $this->strapi->plugin('runtime-sync')->service('status');
        \assert($service instanceof StatusService);

        return ['data' => $service->report()];
    }
}
