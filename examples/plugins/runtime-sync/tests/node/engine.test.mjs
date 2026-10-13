/**
 * The Node engine against the fixtures both engines share: tests/fixtures/engine/*.json for the
 * pure functions, tests/fixtures/projects/* with the goldens under tests/fixtures/expected/* for
 * status and generate. The PHP twin (tests/php/EngineTest.php) writes the goldens; this suite
 * must reproduce them byte for byte. Reading PHP config fixtures needs `php` on the PATH.
 */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, existsSync, mkdtempSync, rmSync, cpSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const root = path.join(path.dirname(new URL(import.meta.url).pathname), '..', '..');
const fixtures = path.join(root, 'tests', 'fixtures');
const json = (rel) => JSON.parse(readFileSync(path.join(fixtures, rel), 'utf8'));
const golden = (rel) => {
  const file = path.join(fixtures, rel);
  assert.ok(existsSync(file), `golden ${rel} missing (run the PHP suite with UPDATE_FIXTURES=1)`);
  return readFileSync(file, 'utf8');
};
const encode = (value) => `${JSON.stringify(value, null, 4)}\n`;

const glob = require('../../engine/glob');
const migrations = require('../../engine/migrations');
const source = require('../../engine/source');
const emit = require('../../engine/emit');
const ir = require('../../engine/ir');
const status = require('../../engine/status');
const generate = require('../../engine/generate');

test('glob', () => {
  for (const c of json('engine/glob.json')) assert.equal(glob.match(c.pattern, c.path), c.expected, `${c.pattern} vs ${c.path}`);
});

test('migrations', () => {
  for (const c of json('engine/migrations.json')) {
    assert.deepEqual(migrations.twinsToLog(c.executed, c.files), c.twinsToLog, c.description);
    assert.deepEqual(migrations.pending(c.executed, c.files), c.pending, c.description);
    for (const [runtime, expected] of Object.entries(c.discoverable)) assert.deepEqual(migrations.discoverable(c.files, runtime), expected, `${c.description} (${runtime})`);
  }
});

test('core factory detection', () => {
  json('engine/core-factory.json').forEach((c, i) => assert.deepEqual(source.detectCoreFactory(c.code, c.lang), c.expected, `case ${i}`));
});

test('emit', () => {
  json('engine/emit.json').forEach((c, i) => {
    const doc = ir.decode(JSON.stringify(c.doc));
    for (const [lang, target] of Object.entries(c.targets)) {
      assert.equal(emit.config(doc, lang, target).content, golden(`expected/emit/${i}.${path.basename(target)}`), `${c.description} (${lang})`);
    }
  });
});

for (const project of readdirSync(path.join(fixtures, 'projects')).filter((d) => statSync(path.join(fixtures, 'projects', d)).isDirectory()).sort()) {
  test(`status and generate: ${project}`, async () => {
    const projectRoot = path.join(fixtures, 'projects', project);

    const rep = status.report(projectRoot);
    rep.root = '<root>';
    assert.equal(encode(rep), golden(`expected/${project}/status.json`), `status of ${project}`);

    const plan = await generate.plan(projectRoot);
    const summary = { targets: plan.targets, actions: [] };
    for (const action of plan.actions) {
      const { content, ...rest } = action;
      summary.actions.push(rest);
      if (content !== null) assert.equal(content, golden(`expected/${project}/generated/${action.target}/${action.path}`), `${project}: ${action.path}`);
    }
    assert.equal(encode(summary), golden(`expected/${project}/plan.json`), `plan of ${project}`);
  });
}

test('apply writes the plan and skips existing files', async () => {
  const tmp = mkdtempSync(path.join(tmpdir(), 'runtime-sync-'));
  cpSync(path.join(fixtures, 'projects', 'node-js'), tmp, { recursive: true });
  try {
    const results = generate.apply(tmp, await generate.plan(tmp, { to: 'php' }));
    const written = results.filter((r) => r.action === 'written').map((r) => r.path);
    assert.ok(written.includes('config/server.php'));
    assert.ok(written.includes('composer.json'));
    assert.ok(existsSync(path.join(tmp, 'bin/strapi')));
    assert.ok(readFileSync(path.join(tmp, 'package.json'), 'utf8').includes('"build:php": "php bin/strapi build"'));

    const again = await generate.plan(tmp, { to: 'php' });
    assert.deepEqual(again.actions.filter((a) => a.action === 'write' || a.action === 'merge').map((a) => a.path), [], 'a second run has nothing left to write');
    assert.equal(status.report(tmp).runtimes.php.present, true);
  } finally {
    rmSync(tmp, { recursive: true, force: true });
  }
});
