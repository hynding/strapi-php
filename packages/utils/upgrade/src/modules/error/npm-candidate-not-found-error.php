<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Error;

use Strapi\Upgrade\Modules\Version\NodeSemver\Range;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Utils\Errors\ApplicationError;

/** Port of `NPMCandidateNotFoundError` (packages/utils/upgrade/src/modules/error/utils.ts). */
class NPMCandidateNotFoundError extends ApplicationError
{
    public string $name = 'NPMCandidateNotFoundError';

    public function __construct(public readonly SemVer|Range|string $target, ?string $message = null)
    {
        $fTarget = $target instanceof Range ? $target->range() : (string) $target;

        parent::__construct($message ?? "Couldn't find a valid NPM candidate for \"{$fTarget}\"");
    }
}
