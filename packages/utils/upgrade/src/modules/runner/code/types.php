<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Runner\Code;

/**
 * Port of packages/utils/upgrade/src/modules/runner/code/types.ts.
 *
 * Upstream's options are jscodeshift's (`parser`, `babel`, `runInBand`, `print`, `verbose`…). The
 * PHP code runner transforms PHP sources, so it keeps `dry`, `extensions` and `silent`, and adds
 * `cwd` (the project root, which upstream's codemods read from `process.cwd()`).
 *
 * A code codemod file returns a `CodeTransform` closure, or null when the codemod has nothing to
 * change in PHP sources (the upstream runner still applies it to the project's JS/TS files).
 *
 * @phpstan-type CodeRunnerConfiguration array{dry?: bool, extensions?: string, silent?: bool, cwd?: string}
 * @phpstan-type CodeSourceFile array{path: string, source: string}
 * @phpstan-type CodeTransform \Closure(CodeSourceFile, TransformAPI, CodeRunnerConfiguration): (string|null)
 */
final class Types
{
}
