<?php

declare(strict_types=1);

/**
 * Port of resources/codemods/5.0.0/strapi-public-interface.code.ts — nothing to change in PHP
 * sources: it rewrites `import strapi from '@strapi/strapi'; strapi()` to `createStrapi()`, a v4
 * JS entry point that strapi-php (5.x only, booted by bin/strapi and public/index.php) never had.
 * Project JS/TS files still get upstream's codemod through `npx @strapi/upgrade` (UpstreamRunner).
 */
return null;
