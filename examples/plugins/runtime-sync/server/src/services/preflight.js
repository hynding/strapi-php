'use strict';

/**
 * Mirrors services/preflight.php. What this boot would do to the database, computed between
 * `db.init()` and `db.schema.sync()` (the `beforeSync` hook), without writing anything; then the
 * boot marker written after the sync.
 */
const fs = require('node:fs');
const path = require('node:path');
const migrations = require('../../../engine/migrations');
const { PreflightAbort } = require('../preflight-abort');

const STORE = { type: 'plugin', name: 'runtime-sync', key: 'boots' };

module.exports = ({ strapi }) => {
  let database = null;

  const runtime = () => ({
    kind: 'node',
    version: String(strapi.config.get('info.strapi')),
    packageVersion: String(strapi.config.get('info.strapi')),
    engine: `Node ${process.version}`,
  });

  const isPreflight = () => process.env.STRAPI_RUNTIME_SYNC_PREFLIGHT === '1';

  const pluginNames = () => Object.keys(strapi.plugins).sort();

  /** The boot markers of both runtimes, from the core store (empty before the first boot). */
  const boots = async () => {
    if (!(await strapi.db.getSchemaConnection().hasTable('strapi_core_store_settings'))) return {};
    const value = await strapi.store.get(STORE);
    return value && typeof value === 'object' ? Object.fromEntries(Object.entries(value).filter(([, v]) => v && typeof v === 'object')) : {};
  };

  const capture = async () => {
    const db = strapi.db;
    const schema = db.schema.schema;
    const storage = db.schema.schemaStorage;
    const hash = storage.hashSchema(schema);

    const stored = (await db.getSchemaConnection().hasTable('strapi_database_schema')) ? await storage.read() : null;
    let tables = { added: [], updated: [], removed: [] };
    let status = 'NEW';
    if (stored) {
      const { status: diffStatus, diff } = await db.schema.schemaDiff.diff({
        previousSchema: stored.schema,
        databaseSchema: await db.dialect.schemaInspector.getSchema(),
        userSchema: schema,
      });
      status = diffStatus === 'CHANGED' ? 'CHANGED' : 'UNCHANGED';
      tables = {
        added: (diff.tables.added ?? []).map((t) => String(t.name)),
        updated: (diff.tables.updated ?? []).map((t) => String(t.name)),
        removed: (diff.tables.removed ?? []).map((t) => String(t.name)),
      };
    }

    const dir = String(db.config?.settings?.migrations?.dir ?? '');
    const files = dir !== '' && fs.existsSync(dir) ? fs.readdirSync(dir).filter((f) => fs.statSync(path.join(dir, f)).isFile()) : [];
    const executed = (await db.getSchemaConnection().hasTable('strapi_migrations')) ? (await db.connection('strapi_migrations').select('name').orderBy('time')).map((row) => String(row.name)) : [];

    return {
      client: String(strapi.config.get('database.connection.client', 'sqlite')),
      schemaHash: hash,
      storedHash: stored ? stored.hash : null,
      storedAt: stored && stored.time ? new Date(stored.time).toISOString() : null,
      hashMatches: stored ? stored.hash === hash : null,
      status,
      tables,
      migrations: {
        pending: migrations.pending(executed, migrations.discoverable(files, 'node')),
        twinsToLog: migrations.twinsToLog(executed, files),
        executed,
      },
    };
  };

  return {
    runtime,
    database: () => database,
    isPreflight,
    boots,
    pluginNames,

    /** `strapi::content-types.beforeSync`: capture, reconcile the migration log, abort when asked. */
    async beforeSync() {
      const report = await capture();
      database = report;

      if (isPreflight()) throw new PreflightAbort({ runtime: runtime(), database: report, boots: await boots() });

      if (strapi.plugin('runtime-sync').config('reconcileMigrations') === true && report.migrations.twinsToLog.length > 0) {
        for (const name of report.migrations.twinsToLog) {
          await strapi.db.connection('strapi_migrations').insert({ name, time: new Date() });
        }
        strapi.log.info(`[runtime-sync] migrations already run on the other runtime, logged: ${report.migrations.twinsToLog.join(', ')}`);
      }
    },

    /** `strapi::content-types.afterSync`: record this boot for the other runtime and the admin page. */
    async afterSync() {
      if (strapi.plugin('runtime-sync').config('recordBoots') !== true) return;
      const db = strapi.db;
      const current = await boots();
      const { kind, ...rest } = runtime();
      current.node = {
        ...rest,
        runtime: 'node',
        time: new Date().toISOString(),
        schemaHash: db.schema.schemaStorage.hashSchema(db.schema.schema),
        plugins: pluginNames(),
        contentTypes: Object.keys(strapi.contentTypes).length,
      };
      await strapi.store.set({ ...STORE, value: current });
    },
  };
};

module.exports.STORE = STORE;
