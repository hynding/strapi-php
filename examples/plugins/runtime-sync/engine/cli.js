'use strict';

/**
 * Mirrors engine/cli.php: `strapi-runtime-sync`, the same commands and words from both runtimes.
 * Everything but `db:check` is file work the engine does; `db:check` boots this runtime's Strapi
 * up to the schema sync, where the plugin's preflight aborts it with the report.
 */
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const files = require('./files');
const generate = require('./generate');
const report = require('./report');
const status = require('./status');

const USAGE = `strapi-runtime-sync: one Strapi project, two runtimes (Strapi on Node, strapi-php)

usage: strapi-runtime-sync <command> [options]

  status                 the project's runtimes, file pairs, manifests and problems
  generate               write the files the other runtime lacks
                           --to node|php|both   (default: the runtime that is missing)
                           --ts                 write TypeScript on the Node side
                           --force              overwrite generated files that exist
                           --dry-run            show the plan, write nothing
  db:check               boot up to the schema sync and report what a boot would change
                           --runtime node|php   (default: this CLI's runtime; the other
                                                 runtime's CLI is run for the other)
  handoff --to node|php  go / no-go for running the project on that runtime

options: --root <dir> (default: the current directory), --json, --help

exit codes: 0 fine, 1 something to fix, 2 usage`;

/** The runtime this CLI belongs to. */
const RUNTIME = 'node';

/** `--key value`, `--key=value`, `--flag`; the first bare word is the command. */
function parse(argv) {
  let command = null;
  const options = {};
  const valued = ['to', 'root', 'runtime'];
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg.startsWith('--')) {
      let name = arg.slice(2);
      let value = true;
      if (name.includes('=')) [name, value] = [name.slice(0, name.indexOf('=')), name.slice(name.indexOf('=') + 1)];
      else if (valued.includes(name) && argv[i + 1] !== undefined && !argv[i + 1].startsWith('--')) value = argv[++i];
      options[name] = value;
    } else if (arg === '-h') {
      options.help = true;
    } else if (command === null) {
      command = arg;
    }
  }
  if (options.help) command = 'help';
  return { command, options };
}

const json = (value) => `${JSON.stringify(value, null, 4)}\n`;

async function run(argv, cwd, out = (text) => process.stdout.write(text)) {
  const { command, options } = parse(argv);
  const root = files.realRoot(String(options.root ?? cwd));
  try {
    switch (command) {
      case 'status':
        return statusCommand(root, options, out);
      case 'generate':
        return generateCommand(root, options, out);
      case 'db:check':
        return dbCheckCommand(root, options, out);
      case 'handoff':
        return handoffCommand(root, options, out);
      case 'help':
      case null:
        out(`${USAGE}\n`);
        return command === null && argv.length > 0 ? 2 : 0;
      default:
        out(`unknown command: ${command}\n\n${USAGE}\n`);
        return 2;
    }
  } catch (e) {
    out(options.json ? `${JSON.stringify({ error: e.message })}\n` : `ERROR ${e.message}\n`);
    return 1;
  }
}

function statusCommand(root, options, out) {
  const rep = status.report(root);
  out(options.json ? json(rep) : report.status(rep));
  return status.hasErrors(rep) ? 1 : 0;
}

async function generateCommand(root, options, out) {
  const to = typeof options.to === 'string' ? options.to : null;
  if (to !== null && !['node', 'php', 'both'].includes(to)) {
    out('--to must be node, php or both\n');
    return 2;
  }
  const plan = await generate.plan(root, { to, language: options.ts ? 'ts' : null, force: Boolean(options.force) });
  const results = generate.apply(root, plan, Boolean(options['dry-run']));
  out(options.json ? json({ targets: plan.targets, results }) : report.generate(results, root));
  return results.some((r) => r.action === 'error') ? 1 : 0;
}

async function dbCheckCommand(root, options, out) {
  const runtime = typeof options.runtime === 'string' ? options.runtime : RUNTIME;
  if (!['node', 'php'].includes(runtime)) {
    out('--runtime must be node or php\n');
    return 2;
  }
  const rep = runtime === RUNTIME ? await preflight(root, Boolean(options.verbose)) : preflightWithOtherCli(root, runtime);
  out(options.json ? json(rep) : report.database(rep));
  return rep.database.status === 'CHANGED' ? 1 : 0;
}

async function handoffCommand(root, options, out) {
  const to = typeof options.to === 'string' ? options.to : null;
  if (!['node', 'php'].includes(to)) {
    out('handoff needs --to node or --to php\n');
    return 2;
  }
  const result = await handoffChecks(root, to, (runtime) => (runtime === RUNTIME ? preflight(root, false) : preflightWithOtherCli(root, runtime)));
  out(options.json ? json(result) : report.handoff(result));
  return result.ready ? 0 : 1;
}

/** The go / no-go list for running the project on `to`. Shared by both CLIs. */
async function handoffChecks(root, to, dbCheck) {
  const rep = status.report(root);
  const runtime = rep.runtimes[to];
  const missing = rep.counts[to === 'node' ? 'missingNode' : 'missingPhp'];
  const manifest = rep.manifests[to];
  const checks = [];
  const check = (name, ok, detail = '', blocking = true) => checks.push({ name, ok, blocking, detail });

  check(`${to} runtime set up`, runtime.present, runtime.present ? `Strapi ${runtime.upstreamVersion ?? '?'}` : `run \`strapi-runtime-sync generate --to ${to}\``);
  check(`no files missing on ${to}`, missing === 0, missing === 0 ? '' : `${missing} file(s): run \`strapi-runtime-sync generate --to ${to}\``);
  check('manifests complete', manifest.missingFiles.length === 0 && manifest.missingDependencies.length === 0, [...manifest.missingFiles, ...manifest.missingDependencies].join(', '));
  check('versions in lockstep', rep.versions.lockstep !== false, rep.versions.lockstep === null ? 'only one runtime declared' : '');
  check('.env complete', rep.env.present && rep.env.missingKeys.length === 0, rep.env.present ? rep.env.missingKeys.join(', ') : 'no .env');
  check('dependencies installed', runtime.installed, runtime.installed ? '' : to === 'php' ? 'run `composer install`' : 'run `npm install`');
  const pluginParts = [];
  if (rep.plugins.nodeOnly.length > 0) pluginParts.push(`node only: ${rep.plugins.nodeOnly.join(', ')}`);
  if (rep.plugins.phpOnly.length > 0) pluginParts.push(`php only: ${rep.plugins.phpOnly.join(', ')}`);
  check('plugins match', pluginParts.length === 0, pluginParts.join('; '), false);
  check('admin build present', rep.adminBuild.present, rep.adminBuild.present ? `${rep.adminBuild.dir}/` : 'run `strapi build` on either runtime', false);

  if (runtime.present && runtime.installed) {
    try {
      const db = await dbCheck(to);
      const s = db.database.status;
      check('database: a boot alters nothing', s !== 'CHANGED', s === 'NEW' ? 'no schema yet: the first boot creates it' : s === 'CHANGED' ? `tables ${JSON.stringify(db.database.tables)}` : `schema ${String(db.database.schemaHash).slice(0, 12)} unchanged`);
    } catch (e) {
      check('database: a boot alters nothing', false, e.message);
    }
  } else {
    check('database: a boot alters nothing', false, 'skipped: the runtime is not set up or installed');
  }

  const ready = checks.every((c) => !c.blocking || c.ok);
  return {
    target: to,
    checks,
    ready,
    commands: to === 'php' ? { develop: 'php bin/strapi develop', start: 'php bin/strapi start' } : { develop: 'npx strapi develop', start: 'npx strapi start' },
  };
}

/** Boots this project's Strapi up to the schema sync; the plugin aborts it with the report. */
async function preflight(root, verbose) {
  process.env.STRAPI_RUNTIME_SYNC_PREFLIGHT = '1';
  let strapiModule;
  try {
    strapiModule = require(require.resolve('@strapi/strapi', { paths: [root] }));
  } catch {
    throw new Error('@strapi/strapi is not installed in this project: run `npm install`');
  }
  const { createStrapi, compileStrapi } = strapiModule;
  const appContext = await compileStrapi({ appDir: root });
  const app = createStrapi({ ...appContext, serveAdminPanel: false });
  if (!verbose && app.log && 'level' in app.log) app.log.level = 'error';

  let report_ = null;
  try {
    await app.load();
  } catch (e) {
    if (e && e.name === 'PreflightAbort') report_ = e.report;
    else throw e;
  }
  try {
    if (report_) await app.db.destroy();
    else await app.destroy();
  } catch {
    // the connection is all that was opened
  }
  if (!report_) throw new Error('the runtime-sync plugin is not enabled in this project: `npm install strapi-plugin-runtime-sync` and enable it in config/plugins');
  return report_;
}

/** The other runtime's CLI, for `db:check --runtime <other>`: its report is the last JSON line it prints. */
function preflightWithOtherCli(root, runtime) {
  const command = otherCliCommand(root, runtime);
  if (command === null) throw new Error(`the ${runtime} CLI is not installed here (${runtime === 'php' ? 'vendor/bin/strapi-runtime-sync' : 'node_modules/.bin/strapi-runtime-sync'})`);
  const result = spawnSync(command[0], [...command.slice(1), 'db:check', '--json', '--root', root], { cwd: root, encoding: 'utf8', timeout: 600000 });
  const decoded = lastJsonObject(result.stdout || '');
  if (!decoded || !decoded.database) throw new Error(`the ${runtime} CLI gave no report: ${(result.stderr || result.stdout || (result.error && result.error.message) || '').trim()}`);
  return decoded;
}

/** The last JSON object printed at column 0 (the report follows whatever the boot logged). */
function lastJsonObject(output) {
  const text = `\n${output.trim()}`;
  const start = text.lastIndexOf('\n{');
  if (start === -1) return null;
  try {
    const decoded = JSON.parse(text.slice(start + 1));
    return decoded && typeof decoded === 'object' ? decoded : null;
  } catch {
    return null;
  }
}

function otherCliCommand(root, runtime) {
  if (runtime === 'php') {
    for (const script of [path.join(root, 'vendor/strapi-plugin/runtime-sync/bin/strapi-runtime-sync'), path.join(__dirname, '..', 'bin', 'strapi-runtime-sync')]) {
      if (fs.existsSync(script)) return [process.env.RUNTIME_SYNC_PHP || 'php', script];
    }
    return null;
  }
  for (const script of [path.join(root, 'node_modules/strapi-plugin-runtime-sync/bin/strapi-runtime-sync.js'), path.join(__dirname, '..', 'bin', 'strapi-runtime-sync.js')]) {
    if (fs.existsSync(script)) return [process.env.RUNTIME_SYNC_NODE || process.execPath, script];
  }
  return null;
}

module.exports = { USAGE, RUNTIME, parse, run, handoffChecks, preflight, preflightWithOtherCli, lastJsonObject, otherCliCommand };
