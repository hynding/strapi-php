import knex from 'knex';
import { readFileSync, writeFileSync } from 'fs';
const schema = JSON.parse(readFileSync('/home/claude/strapi-php/packages/core/database/tests/fixtures/getstarted-schema.json', 'utf8'));
const db = knex({ client: 'better-sqlite3', connection: { filename: ':memory:' }, useNullAsDefault: true });
(async () => {
  await db.raw('pragma foreign_keys = on');
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
  const out: any = {};
  for (const table of schema.tables) {
    out[table.name] = {
      columns: await db.raw('pragma table_info(??)', [table.name]),
      indexes: (await db.raw('pragma index_list(??)', [table.name])).filter((i: any) => !i.name.startsWith('sqlite_')).map((i: any) => ({ name: i.name, unique: !!i.unique })),
      fks: await db.raw('pragma foreign_key_list(??)', [table.name]),
      sql: (await db.raw("select sql from sqlite_master where name = ?", [table.name]))[0].sql,
    };
  }
  writeFileSync('/home/claude/strapi-php/packages/core/database/tests/fixtures/getstarted-sqlite.json', JSON.stringify(out, null, 2));
  console.log('ok', Object.keys(out).length);
  await db.destroy();
})();
