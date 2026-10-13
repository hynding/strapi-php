#!/usr/bin/env node
/**
 * Runs conformance/fixtures/*.json against real Strapi (the version in devDependencies) with this
 * plugin enabled, then checks the preflight and the boot marker the way tests/php/ServerTest.php
 * does on strapi-php. The fixture rules are the announcements plugin's (conformance/run-strapi.mjs
 * there): seed through the document service, `$keys` for exact key sets, subset matching otherwise.
 *
 * Needs `npm run build` first: Strapi loads the plugin from dist/ through its package exports.
 */
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const pluginDir = join(dirname(fileURLToPath(import.meta.url)), '..');
const fixturesDir = join(pluginDir, 'conformance/fixtures');
const appDir = join(pluginDir, '.tmp/conformance-app');
const require = createRequire(join(pluginDir, 'package.json'));

const SUPPORTS = ['admin'];

// --- a throwaway Strapi app with only this plugin -------------------------------------------

rmSync(appDir, { recursive: true, force: true });
for (const dir of ['config', 'src', 'public/uploads', 'database/migrations']) mkdirSync(join(appDir, dir), { recursive: true });

const files = {
  'package.json': JSON.stringify({ name: 'conformance-app', version: '0.0.0', private: true, dependencies: { '@strapi/strapi': require('./package.json').devDependencies['@strapi/strapi'] }, strapi: { uuid: 'conformance' } }),
  'src/index.js': 'module.exports = { register() {}, bootstrap() {} };\n',
  'config/database.js': `module.exports = () => ({ connection: { client: 'sqlite', connection: { filename: ${JSON.stringify(join(appDir, 'data.db'))} }, useNullAsDefault: true } });\n`,
  'config/server.js': `module.exports = () => ({ host: '127.0.0.1', port: 0, app: { keys: ['conformance-1', 'conformance-2'] } });\n`,
  'config/admin.js': `module.exports = () => ({ serveAdminPanel: false, auth: { secret: 'conformance' }, apiToken: { salt: 'conformance' }, transfer: { token: { salt: 'conformance' } }, secrets: { encryptionKey: 'conformance' } });\n`,
  'config/plugins.js': `module.exports = () => ({ 'runtime-sync': { enabled: true, resolve: ${JSON.stringify(pluginDir)} } });\n`,
  'config/middlewares.js': `module.exports = ['strapi::logger', 'strapi::errors', 'strapi::security', 'strapi::cors', 'strapi::poweredBy', 'strapi::query', 'strapi::body', 'strapi::session', 'strapi::favicon', 'strapi::public'];\n`,
  'config/logger.js': `module.exports = () => ({ level: 'error' });\n`,
  // the Node twin of a migration strapi-php already ran on this database (its .php name gets logged below)
  'database/migrations/2024.01.01T00.00.00.noop.js': "module.exports = { async up() { throw new Error('must not run: the .php twin is logged'); }, async down() {} };\n",
  'database/migrations/2024.01.01T00.00.00.noop.php': '<?php return [];\n',
};
for (const [path, content] of Object.entries(files)) writeFileSync(join(appDir, path), content);
writeFileSync(join(appDir, 'favicon.png'), Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64'));

// a migration log a strapi-php boot left behind
{
  const Database = require('better-sqlite3');
  const db = new Database(join(appDir, 'data.db'));
  db.exec('CREATE TABLE strapi_migrations (id integer primary key autoincrement, name varchar(255), time datetime)');
  db.prepare('INSERT INTO strapi_migrations (name, time) VALUES (?, ?)').run('2024.01.01T00.00.00.noop.php', '2024-01-01 00:00:00');
  db.close();
}

process.env.STRAPI_TELEMETRY_DISABLED = 'true';
process.env.STRAPI_DISABLE_UPDATE_NOTIFICATION = 'true';
process.env.NODE_ENV = 'test';

const { createStrapi } = require('@strapi/strapi');
const strapi = createStrapi({ appDir, distDir: appDir });
await strapi.load();
await new Promise((resolve) => strapi.server.listen(resolve));
const base = `http://127.0.0.1:${strapi.server.httpServer.address().port}`;

// --- fixture machinery (as examples/plugins/announcements/conformance/run-strapi.mjs) ----------

function match(expected, actual, path = 'body') {
  if (expected === null || typeof expected !== 'object') {
    return Object.is(expected, actual) ? [] : [`${path}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`];
  }
  if (Array.isArray(expected)) {
    if (!Array.isArray(actual)) return [`${path}: expected an array, got ${JSON.stringify(actual)}`];
    if (expected.length !== actual.length) return [`${path}: expected ${expected.length} items, got ${actual.length}`];
    return expected.flatMap((item, i) => match(item, actual[i], `${path}[${i}]`));
  }
  if (actual === null || typeof actual !== 'object' || Array.isArray(actual)) return [`${path}: expected an object, got ${JSON.stringify(actual)}`];
  const problems = [];
  if (expected.$keys) {
    const want = [...expected.$keys].sort();
    const got = Object.keys(actual).sort();
    if (JSON.stringify(want) !== JSON.stringify(got)) problems.push(`${path}: expected keys ${want.join(',')}, got ${got.join(',')}`);
  }
  for (const [key, value] of Object.entries(expected)) {
    if (key === '$keys') continue;
    if (!(key in actual)) problems.push(`${path}.${key}: missing`);
    else problems.push(...match(value, actual[key], `${path}.${key}`));
  }
  return problems;
}

let adminToken = null;
async function getAdminToken() {
  if (adminToken) return adminToken;
  const res = await fetch(`${base}/admin/register-admin`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'admin@example.com', firstname: 'Ad', lastname: 'Min', password: 'Conformance1!' }),
  });
  const body = await res.json();
  adminToken = body?.data?.accessToken ?? body?.data?.token;
  if (!adminToken) throw new Error(`could not register an admin: ${res.status} ${JSON.stringify(body)}`);
  return adminToken;
}

async function runFixture(fixture) {
  for (const uid of Object.keys(fixture.seed ?? {})) await strapi.db.query(uid).deleteMany({ where: {} });
  for (const [uid, rows] of Object.entries(fixture.seed ?? {})) for (const data of rows) await strapi.documents(uid).create({ data });

  const problems = [];
  for (const [i, step] of fixture.steps.entries()) {
    const headers = { Accept: 'application/json' };
    if (step.auth === 'admin') headers.Authorization = `Bearer ${await getAdminToken()}`;
    if (step.request.body !== undefined) headers['Content-Type'] = 'application/json';
    const res = await fetch(base + step.request.path, { method: step.request.method, headers, body: step.request.body === undefined ? undefined : JSON.stringify(step.request.body) });
    const text = await res.text();
    const body = text ? JSON.parse(text) : null;
    const label = `step ${i + 1} (${step.request.method} ${step.request.path})`;
    if (res.status !== step.expect.status) problems.push(`${label}: expected status ${step.expect.status}, got ${res.status} ${text.slice(0, 300)}`);
    if ('body' in step.expect) problems.push(...match(step.expect.body, body).map((p) => `${label}: ${p}`));
  }
  return problems;
}

// --- run ----------------------------------------------------------------------------------------

let failed = 0;
const report = (name, problems) => {
  if (problems.length) {
    failed++;
    console.log(`FAIL ${name}`);
    for (const p of problems) console.log(`     ${p}`);
  } else console.log(`ok   ${name}`);
};

for (const file of readdirSync(fixturesDir).filter((f) => f.endsWith('.json')).sort()) {
  const fixture = JSON.parse(readFileSync(join(fixturesDir, file), 'utf8'));
  const missing = (fixture.requires ?? []).filter((r) => !SUPPORTS.includes(r));
  if (missing.length) {
    console.log(`skip ${file}: needs ${missing.join(', ')}`);
    continue;
  }
  report(`${file}: ${fixture.description}`, await runFixture(fixture));
}

// the server half, as tests/php/ServerTest.php checks it on strapi-php
{
  const status = await strapi.plugin('runtime-sync').service('status').report();
  const problems = [];
  if (status.runtime.kind !== 'node') problems.push(`runtime.kind: ${status.runtime.kind}`);
  if (!status.boots.node || status.boots.node.schemaHash !== status.database.schemaHash) problems.push('boots.node must record this boot with the schema hash');
  const log = (await strapi.db.connection('strapi_migrations').select('name')).map((r) => r.name).sort();
  if (JSON.stringify(log) !== JSON.stringify(['2024.01.01T00.00.00.noop.js', '2024.01.01T00.00.00.noop.php'])) problems.push(`migration log: ${log.join(', ')} (the .js twin of the logged .php must be logged, not run)`);
  if (!status.database.migrations.twinsToLog.includes('2024.01.01T00.00.00.noop.js')) problems.push('database.migrations.twinsToLog must name the .js twin');
  report('server: boot marker recorded, PHP-run migration twin logged instead of run', problems);
}
await strapi.destroy();

{
  // a second boot, in preflight mode: aborted before writing, status UNCHANGED, stored by node
  process.env.STRAPI_RUNTIME_SYNC_PREFLIGHT = '1';
  const again = createStrapi({ appDir, distDir: appDir });
  const problems = [];
  try {
    await again.load();
    problems.push('the boot was not aborted');
  } catch (e) {
    if (e.name !== 'PreflightAbort') problems.push(`unexpected error: ${e.message}`);
    else {
      if (e.report.database.status !== 'UNCHANGED') problems.push(`status ${e.report.database.status}, expected UNCHANGED`);
      if (e.report.database.hashMatches !== true) problems.push('hashMatches must be true');
      if (!e.report.boots.node) problems.push('boots.node missing');
    }
  }
  try {
    await again.db.destroy();
  } catch {
    // nothing else was opened
  }
  report('server: preflight boot aborts with UNCHANGED on the second boot', problems);
}

process.exit(failed ? 1 : 0);
