<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Project;

/**
 * Port of packages/utils/upgrade/src/modules/project/types.ts.
 *
 * @phpstan-type ProjectType 'plugin'|'application'
 * @phpstan-type RunCodemodsOptions array{dry?: bool, upstreamCommand?: list<string>|null}
 * @phpstan-type MinimalPackageJSON array<string, mixed>
 * @phpstan-type MinimalComposerJSON array<string, mixed>
 * @phpstan-type ProjectConfig array{paths: list<string>}
 */
final class Types
{
}
