<?php

declare(strict_types=1);

// package.json exports "./strapi-server" → server/src/index; core's admin provider requires this file.
return require __DIR__ . '/server/src/index.php';
