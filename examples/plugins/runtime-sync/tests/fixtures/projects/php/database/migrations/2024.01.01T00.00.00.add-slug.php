<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Strapi\Database\Database;

return [
    'up' => static function (Connection $trx, Database $db): void {
        $trx->executeStatement('ALTER TABLE articles ADD COLUMN slug VARCHAR(255)');
    },
    'down' => static function (Connection $trx, Database $db): void {
    },
];
