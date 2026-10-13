<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Strapi\Database\Database;

/** The PHP twin of a migration a Node Strapi already ran on this database (its .js twin is logged). */
return [
    'up' => static function (Connection $trx, Database $db): void {
        throw new \LogicException('must not run: the .js twin is logged');
    },
    'down' => static function (): void {
    },
];
