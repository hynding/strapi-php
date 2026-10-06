# Node-side fixture generators

These `tsx` scripts regenerate the parity fixtures in `../` by running the real upstream
TypeScript (`transformContentTypesToModels`, `createMetadata`, `metadataToSchema`) and a
real Knex 3 + better-sqlite3 database, so `SchemaParityTest` and `SharedDatabaseTest` can
assert that this port produces byte-identical schemas, hashes and DDL.

Regenerate when upstream changes:

```sh
# from a checkout of strapi/strapi at the tracked tag
cd <scratch> && npm install knex better-sqlite3 lodash date-fns @paralleldrive/cuid2 tsx
cd <strapi checkout>
NODE_PATH=<scratch>/node_modules npx tsx <this dir>/gen-schema.ts   # → getstarted-schema.json, getstarted-metadata.json
NODE_PATH=<scratch>/node_modules npx tsx <this dir>/gen-ddl.ts      # → getstarted-sqlite.json
NODE_PATH=<scratch>/node_modules npx tsx <this dir>/gen-knexdb.ts   # → getstarted-knex.db
```
