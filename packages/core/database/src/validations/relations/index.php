<?php

declare(strict_types=1);

namespace Strapi\Database\Validations\Relations;

use Strapi\Database\Database;

/** Port of packages/core/database/src/validations/relations/index.ts. */
final class Relations
{
    public static function validateRelations(Database $db): void
    {
        Bidirectional::validateBidirectionalRelations($db);
    }
}
