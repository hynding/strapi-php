<?php

declare(strict_types=1);

/**
 * Port of resources/codemods/5.0.0/utils-public-interface.code.ts — nothing to change in PHP
 * sources: it moves v4 flat `@strapi/utils` helpers (`nameToSlug`, `mapAsync`…) under their v5
 * namespaces (`strings`, `async`…). strapi/utils never exposed the v4 flat API. Project JS/TS
 * files still get upstream's codemod through `npx @strapi/upgrade` (UpstreamRunner).
 */
return null;
