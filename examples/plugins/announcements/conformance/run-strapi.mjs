#!/usr/bin/env node
/**
 * Runs conformance/fixtures/*.json against real Strapi (the version in devDependencies) with this
 * plugin enabled. The PHP twin is tests/php/ConformanceTest.php; both apply the same rules:
 *
 *   - before each fixture every seeded content type is emptied, then `seed` rows are created in order
 *     through the document service
 *   - each step sends `request` and compares the response with `expect`:
 *       objects    every expected key must match; extra actual keys are allowed,
 *                  unless the object lists `"$keys"`, which must equal the actual key set
 *       arrays     same length, matched element by element
 *       scalars    strict equality
 *   - `"auth": "admin"` sends a super-admin token; fixtures list such needs in `requires`
 *
 * Needs `npm run build` first: Strapi loads the plugin from dist/ through its package exports.
 */
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const pluginDir = join(dirname(fileURLToPath(import.meta.url)), '..');
const fixturesDir = join(pluginDir, 'conformance/fixtures');
const appDir = join(pluginDir, '.tmp/conformance-app');
const require = createRequire(join(pluginDir, 'package.json'));

const SUPPORTS = ['admin'];

// --- a throwaway Strapi app with only this plugin -------------------------------------------

rmSync(appDir, { recursive: true, force: true });
mkdirSync(join(appDir, 'config'), { recursive: true });
mkdirSync(join(appDir, 'src'), { recursive: true });
mkdirSync(join(appDir, 'public/uploads'), { recursive: true });

const files = {
  'package.json': JSON.stringify({ name: 'conformance-app', version: '0.0.0', private: true, strapi: { uuid: 'conformance' } }),
  'src/index.js': 'module.exports = { register() {}, bootstrap() {} };\n',
  'config/database.js': `module.exports = () => ({ connection: { client: 'sqlite', connection: { filename: ${JSON.stringify(join(appDir, 'data.db'))} }, useNullAsDefault: true } });\n`,
  'config/server.js': `module.exports = () => ({ host: '127.0.0.1', port: 0, app: { keys: ['conformance-1', 'conformance-2'] } });\n`,
  'config/admin.js': `module.exports = () => ({ serveAdminPanel: false, auth: { secret: 'conformance' }, apiToken: { salt: 'conformance' }, transfer: { token: { salt: 'conformance' } }, secrets: { encryptionKey: 'conformance' } });\n`,
  'config/plugins.js': `module.exports = () => ({ announcements: { enabled: true, resolve: ${JSON.stringify(pluginDir)} } });\n`,
  'config/middlewares.js': `module.exports = ['strapi::logger', 'strapi::errors', 'strapi::security', 'strapi::cors', 'strapi::poweredBy', 'strapi::query', 'strapi::body', 'strapi::session', 'strapi::favicon', 'strapi::public'];\n`,
  'config/logger.js': `module.exports = () => ({ level: 'error' });\n`,
};
for (const [path, content] of Object.entries(files)) writeFileSync(join(appDir, path), content);
// strapi::favicon is a required middleware; a 1x1 PNG keeps it happy
writeFileSync(join(appDir, 'favicon.png'), Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64'));

process.env.STRAPI_TELEMETRY_DISABLED = 'true';
process.env.STRAPI_DISABLE_UPDATE_NOTIFICATION = 'true';
process.env.NODE_ENV = 'test';

const { createStrapi } = require('@strapi/strapi');
const strapi = createStrapi({ appDir, distDir: appDir });
await strapi.load();
await new Promise((resolve) => strapi.server.listen(resolve));
const base = `http://127.0.0.1:${strapi.server.httpServer.address().port}`;

// --- fixture machinery ------------------------------------------------------------------------

function match(expected, actual, path = 'body') {
  if (expected === null || typeof expected !== 'object') {
    return Object.is(expected, actual) ? [] : [`${path}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`];
  }
  if (Array.isArray(expected)) {
    if (!Array.isArray(actual)) return [`${path}: expected an array, got ${JSON.stringify(actual)}`];
    if (expected.length !== actual.length) return [`${path}: expected ${expected.length} items, got ${actual.length}`];
    return expected.flatMap((item, i) => match(item, actual[i], `${path}[${i}]`));
  }
  if (actual === null || typeof actual !== 'object' || Array.isArray(actual)) {
    return [`${path}: expected an object, got ${JSON.stringify(actual)}`];
  }
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
  for (const [uid, rows] of Object.entries(fixture.seed ?? {})) {
    for (const data of rows) await strapi.documents(uid).create({ data });
  }

  const problems = [];
  for (const [i, step] of fixture.steps.entries()) {
    const headers = { Accept: 'application/json' };
    if (step.auth === 'admin') headers.Authorization = `Bearer ${await getAdminToken()}`;
    if (step.request.body !== undefined) headers['Content-Type'] = 'application/json';

    const res = await fetch(base + step.request.path, {
      method: step.request.method,
      headers,
      body: step.request.body === undefined ? undefined : JSON.stringify(step.request.body),
    });
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
for (const file of readdirSync(fixturesDir).filter((f) => f.endsWith('.json')).sort()) {
  const fixture = JSON.parse(readFileSync(join(fixturesDir, file), 'utf8'));
  const missing = (fixture.requires ?? []).filter((r) => !SUPPORTS.includes(r));
  if (missing.length) {
    console.log(`skip ${file}: needs ${missing.join(', ')}`);
    continue;
  }
  const problems = await runFixture(fixture);
  if (problems.length) {
    failed++;
    console.log(`FAIL ${file}: ${fixture.description}`);
    for (const p of problems) console.log(`     ${p}`);
  } else {
    console.log(`ok   ${file}: ${fixture.description}`);
  }
}

await strapi.destroy();
process.exit(failed ? 1 : 0);
