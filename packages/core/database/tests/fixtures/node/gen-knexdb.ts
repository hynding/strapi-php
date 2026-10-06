import knex from 'knex';
import { readFileSync } from 'fs';
import crypto from 'crypto';
const schema = JSON.parse(readFileSync('/home/claude/strapi-php/packages/core/database/tests/fixtures/getstarted-schema.json', 'utf8'));
const file = '/home/claude/strapi-php/packages/core/database/tests/fixtures/getstarted-knex.sqlite';
try { require('fs').unlinkSync(file); } catch {}
const db = knex({ client: 'better-sqlite3', connection: { filename: file }, useNullAsDefault: true });
(async () => {
  for (const table of schema.tables) {
    await db.schema.createTable(table.name, (t: any) => {
      for (const column of table.columns) {
        const { type, name, args = [], defaultTo, unsigned, notNullable } = column;
        const col = t[type](name, ...args);
        if (unsigned) col.unsigned();
        if (defaultTo != null) col.defaultTo(defaultTo);
        if (notNullable) col.notNullable(); else col.nullable();
      }
      for (const index of table.indexes) {
        if (index.type === 'primary') t.primary(index.columns, { constraintName: index.name });
        else if (index.type === 'unique') t.unique(index.columns, { indexName: index.name });
        else t.index(index.columns, index.name, index.type);
      }
      for (const fk of table.foreignKeys) {
        const c = t.foreign(fk.columns, fk.name).references(fk.referencedColumns).inTable(fk.referencedTable);
        if (fk.onDelete) c.onDelete(fk.onDelete);
        if (fk.onUpdate) c.onUpdate(fk.onUpdate);
      }
    });
  }
  // storage.ts
  await db.schema.createTable('strapi_database_schema', (t) => { t.increments('id'); t.json('schema'); t.datetime('time', { useTz: false }); t.string('hash'); });
  const sorted = { ...schema, tables: [...schema.tables].sort((a, b) => a.name.localeCompare(b.name)) };
  const hash = crypto.createHash('sha256').update(JSON.stringify(sorted)).digest('hex');
  await db('strapi_database_schema').insert({ schema: JSON.stringify(schema), hash, time: new Date() });
  for (const t of ['strapi_migrations', 'strapi_migrations_internal']) await db.schema.createTable(t, (tb) => { tb.increments('id'); tb.string('name'); tb.datetime('time', { useTz: false }); });
  for (const name of ['5.0.0-01-convert-identifiers-long-than-max-length','5.0.0-02-created-document-id','5.0.0-03-created-locale','5.0.0-04-created-published-at','5.0.0-05-drop-slug-fields-index','5.0.0-06-add-document-id-indexes']) await db('strapi_migrations_internal').insert({ name, time: new Date() });
  // a row with every scalar type, written the way knex/strapi write them
  await db('kitchensinks').insert({ document_id: 'abcdefghijklmnopqrstuvwx', short_text: 'from-node', integer: 7, biginteger: '123456789012345', decimal: 1.5, float: 2.25, date: '2024-01-02', datetime: new Date('2024-01-02T03:04:05.678Z'), time: '03:04:05.000', timestamp: new Date('2024-01-02T03:04:05.000Z'), boolean: true, json: JSON.stringify({ a: 1 }), created_at: new Date('2024-01-02T03:04:05.000Z'), updated_at: new Date('2024-01-02T03:04:05.000Z'), published_at: new Date('2024-01-02T03:04:05.000Z') });
  console.log('hash', hash);
  await db.destroy();
})();
