<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Runner\Json;

/**
 * Port of packages/utils/upgrade/src/modules/runner/json/types.ts.
 *
 * A JSON codemod file returns a `JSONTransform` closure (upstream: `export default transform`).
 *
 * @phpstan-type JSONRunnerConfiguration array{dry?: bool, cwd: string}
 * @phpstan-type JSONSourceFile array{path: string, json: array<string, mixed>}
 * @phpstan-type JSONTransformParams array{cwd: string, json: \Closure(array<string, mixed>): \Strapi\Upgrade\Modules\Json\JSONTransformAPI}
 * @phpstan-type JSONTransform \Closure(JSONSourceFile, JSONTransformParams): (array<string, mixed>|null)
 */
final class Types
{
}
