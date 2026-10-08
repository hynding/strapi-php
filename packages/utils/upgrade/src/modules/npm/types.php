<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Npm;

/**
 * Port of packages/utils/upgrade/src/modules/npm/types.ts. Registry documents are kept as decoded
 * arrays; the tool only reads `versions` and each version's `version`. The `Package` interface
 * is `PackageInterface` (one class per file).
 *
 * @phpstan-type NPMPackageVersion array<string, mixed>
 * @phpstan-type NPMPackage array{versions: array<string, NPMPackageVersion>}
 * @phpstan-type FetchResponse array{ok: bool, status: int, body: string}
 * @phpstan-type Fetcher \Closure(string): FetchResponse
 */
final class Types
{
}
