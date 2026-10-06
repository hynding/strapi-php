<?php

declare(strict_types=1);

namespace Strapi\Database\Validations;

use Strapi\Database\Database;
use Strapi\Database\Validations\Relations\Relations;

/** Port of packages/core/database/src/validations/index.ts: validate the DB state before starting. */
final class Validations
{
    public static function validateDatabase(Database $db): void
    {
        Relations::validateRelations($db);
    }
}
