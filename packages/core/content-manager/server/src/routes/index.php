<?php

declare(strict_types=1);

/**
 * Port of server/src/routes/index.ts.
 *
 * history/ and preview/ are under Strapi's Enterprise licence (their own LICENSE file) and are
 * not ported, so their routers are not merged.
 */

$homepage = require __DIR__ . '/../homepage/index.php';

return [
    'admin' => require __DIR__ . '/admin.php',
    ...$homepage['routes'],
];
