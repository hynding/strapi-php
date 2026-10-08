<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Json;

/**
 * Port of packages/utils/upgrade/src/modules/json/types.ts (the `JSONTransformAPI` interface is
 * the `JSONTransformAPI` class itself).
 *
 * JSON values are decoded to PHP arrays, except empty objects, which stay `\stdClass` so that
 * `{}` and `[]` survive a read/write round trip.
 *
 * @phpstan-type JSONValue mixed
 * @phpstan-type JSONObject array<string, mixed>
 */
final class Types
{
}
