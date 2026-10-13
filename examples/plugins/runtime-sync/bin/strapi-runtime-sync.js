#!/usr/bin/env node
'use strict';

/**
 * `strapi-runtime-sync` for a Node Strapi project: `npx strapi-runtime-sync status`.
 * The PHP twin is bin/strapi-runtime-sync (`php vendor/bin/strapi-runtime-sync status`).
 */
require('../engine/cli')
  .run(process.argv.slice(2), process.cwd())
  .then((code) => process.exit(code))
  .catch((error) => {
    console.error(error && error.stack ? error.stack : String(error));
    process.exit(1);
  });
