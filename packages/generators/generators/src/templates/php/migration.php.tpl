<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Strapi\Database\Database;

/**
 * Migration `{{ name }}`
 */
return [
    /**
     * Runs in a transaction: `$trx` is the DBAL connection, `$db` the Strapi database.
     */
    'up' => static function (Connection $trx, Database $db): void {
    },
];
