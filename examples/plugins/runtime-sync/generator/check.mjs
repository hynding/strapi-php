#!/usr/bin/env node
/**
 * `npm run check`: the two implementations stay in step.
 *
 *   - every hand-written server file and engine module exists in both languages, same path,
 *     same name (`engine/emit.js` ↔ `engine/emit.php`, `server/src/services/status.js` ↔ `.php`);
 *   - every route handler in server/src/routes/*.json is implemented by both controllers
 *     (the TS side has no `satisfies` here: controllers are plain JS, so both are checked by name);
 *   - package.json and composer.json name the same plugin and the same upstream release.
 *
 * No dependencies: plain Node.
 */
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, dirname, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const readJson = (path) => JSON.parse(readFileSync(join(root, path), 'utf8'));
const pkg = readJson('package.json');
const composer = readJson('composer.json');
const problems = [];

function walk(dir) {
  if (!existsSync(join(root, dir))) return [];
  return readdirSync(join(root, dir)).flatMap((name) => {
    const path = `${dir}/${name}`;
    return statSync(join(root, path)).isDirectory() ? walk(path) : [path];
  });
}

// parity: .js ↔ .php, same stem
for (const dir of ['engine', 'server/src']) {
  const files = walk(dir).filter((p) => !p.includes('/generated/'));
  const stems = (ext) => new Set(files.filter((p) => extname(p) === ext).map((p) => p.slice(0, -ext.length)));
  const js = stems('.js');
  const php = stems('.php');
  for (const stem of js) if (!php.has(stem)) problems.push(`parity: ${stem}.js has no ${stem}.php`);
  for (const stem of php) if (!js.has(stem)) problems.push(`parity: ${stem}.php has no ${stem}.js`);
}

// route handlers: every `controller.action` of routes/*.json exists in both controllers
const handlers = {};
for (const file of readdirSync(join(root, 'server/src/routes')).filter((f) => f.endsWith('.json'))) {
  for (const route of readJson(`server/src/routes/${file}`).routes ?? []) {
    const [controller, action] = route.handler.split('.');
    (handlers[controller] ??= new Set()).add(action);
  }
}
for (const [controller, actions] of Object.entries(handlers)) {
  const js = readFileSync(join(root, `server/src/controllers/${controller}.js`), 'utf8');
  const php = readFileSync(join(root, `server/src/controllers/${controller}.php`), 'utf8');
  for (const action of actions) {
    if (!new RegExp(`(async\\s+)?${action}\\s*\\(`).test(js)) problems.push(`handler ${controller}.${action}: not implemented in controllers/${controller}.js`);
    if (!new RegExp(`function\\s+${action}\\s*\\(`).test(php)) problems.push(`handler ${controller}.${action}: not implemented in controllers/${controller}.php`);
  }
}

// manifests: same plugin name, same upstream release (the PHP side may carry a pre-release or a fourth number)
if (composer.extra.strapi.name !== pkg.strapi.name) problems.push(`manifest: composer.json extra.strapi.name "${composer.extra.strapi.name}" != package.json strapi.name "${pkg.strapi.name}"`);
const upstreamOf = (v) => (String(v).match(/^v?(\d+\.\d+\.\d+)/) || [null, v])[1];
if (upstreamOf(composer.version) !== upstreamOf(pkg.version)) problems.push(`manifest: composer.json version ${composer.version} != package.json version ${pkg.version} (versions move in lockstep)`);
if (pkg.bin['strapi-runtime-sync'] !== './bin/strapi-runtime-sync.js' || !composer.bin.includes('bin/strapi-runtime-sync')) problems.push('manifest: both manifests must publish the strapi-runtime-sync binary');

if (problems.length) {
  for (const p of problems) console.error(p);
  process.exit(1);
}
console.log(`ok: engine and server in parity, ${Object.values(handlers).reduce((n, s) => n + s.size, 0)} route handler(s) implemented twice, manifests in lockstep`);
