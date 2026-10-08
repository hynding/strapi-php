<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Codemod;

/**
 * Port of packages/utils/upgrade/src/modules/codemod/types.ts (the `Codemod` interface is the
 * `Codemod` class itself).
 *
 * @phpstan-type Kind 'code'|'json'
 * @phpstan-type UID string
 * @phpstan-type FormatOptions array{stripKind?: bool, stripExtension?: bool, stripHyphens?: bool}
 * @phpstan-type CodemodList list<Codemod>
 * @phpstan-type VersionedCollection array{version: \Strapi\Upgrade\Modules\Version\NodeSemver\SemVer, codemods: list<Codemod>}
 */
final class Types
{
}
