'use strict';

/**
 * Runs upstream's API suite directory by directory and summarizes the result.
 *
 *   npm test                                   # every directory (Enterprise ones excluded), 2 lanes
 *   npm test -- core/admin plugins/i18n        # some directories (or files), relative to tests/api upstream
 *   npm test -- --lanes 3 --name before-fix    # name the run (default: a timestamp)
 *   npm test -- --compare before-fix           # compare with that run (default: the previous run)
 *
 * Each directory runs in its own Jest process with its own scratch dir (STRAPI_API_TESTS_TMP), so
 * lanes don't share an app or a database. Reports and logs go to .results/<run>/<dir>.{json,log};
 * `node scripts/summary.js <run> --failures` reads them again later.
 *
 * Needs scripts/setup.sh first (or STRAPI_UPSTREAM and FRANKENPHP_BIN).
 */

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { upstreamDir, apiTestsDir } = require('../lib/paths');
const { summarize, resultsDir } = require('./summary');

/** whole packages under upstream's Enterprise licence: not ported (README.md) */
const ENTERPRISE = ['core/content-releases', 'core/review-workflows', 'plugins/content-releases'];

const args = process.argv.slice(2);
const option = (name, fallback) => {
  const i = args.indexOf(`--${name}`);
  if (i < 0) return fallback;
  const [value] = args.splice(i, 2).slice(1);
  return value;
};

const lanes = Math.max(1, Number(option('lanes', 2)));
const name = option('name', new Date().toISOString().slice(0, 16).replace(/[-:]/g, '').replace('T', '-'));
let compare = option('compare', null);

const upstreamTests = path.join(upstreamDir, 'tests', 'api');
if (!fs.existsSync(upstreamTests)) {
  console.error(`No upstream checkout at ${upstreamDir}: run tests/api/scripts/setup.sh or set STRAPI_UPSTREAM`);
  process.exit(2);
}

const targets = args.length
  ? args
  : ['core', 'plugins'].flatMap((group) =>
      fs
        .readdirSync(path.join(upstreamTests, group), { withFileTypes: true })
        .filter((d) => d.isDirectory() && !ENTERPRISE.includes(`${group}/${d.name}`))
        .map((d) => `${group}/${d.name}`)
    );

const runDir = path.join(resultsDir, name);
if (fs.existsSync(runDir)) {
  console.error(`A run named "${name}" exists already (${runDir})`);
  process.exit(2);
}

if (compare === null && fs.existsSync(resultsDir)) {
  const runs = fs
    .readdirSync(resultsDir)
    .map((run) => ({ run, time: fs.statSync(path.join(resultsDir, run)).mtimeMs }))
    .sort((a, b) => b.time - a.time);
  compare = runs.length ? runs[0].run : null;
}

fs.mkdirSync(runDir, { recursive: true });
// scratch apps of earlier runs (this run's stay for its FrankenPHP logs: .tmp/runs/<run>/<dir>/app/.tmp),
// except those of a run still in progress (its .pid names a live process)
const runsTmp = path.join(apiTestsDir, '.tmp', 'runs');
const isRunning = (dir) => {
  try {
    process.kill(Number(fs.readFileSync(path.join(dir, '.pid'), 'utf8')), 0);
    return true;
  } catch {
    return false;
  }
};
for (const run of fs.existsSync(runsTmp) ? fs.readdirSync(runsTmp) : []) {
  if (!isRunning(path.join(runsTmp, run))) fs.rmSync(path.join(runsTmp, run), { recursive: true, force: true });
}
fs.mkdirSync(path.join(runsTmp, name), { recursive: true });
fs.writeFileSync(path.join(runsTmp, name, '.pid'), String(process.pid));

const runOne = (target) =>
  new Promise((resolve) => {
    const slug = target.replace(/\.test\.api\.[jt]s$/, '').replace(/[/\\]/g, '-');
    const log = fs.openSync(path.join(runDir, `${slug}.log`), 'w');
    const started = Date.now();
    const child = spawn(
      'npx',
      [
        'jest', '--config', 'jest.config.js', '--runInBand', '--forceExit',
        path.join(upstreamTests, target),
        '--json', `--outputFile=${path.join(runDir, `${slug}.json`)}`,
      ],
      {
        cwd: apiTestsDir,
        stdio: ['ignore', log, log],
        env: { ...process.env, STRAPI_API_TESTS_TMP: path.join(apiTestsDir, '.tmp', 'runs', name, slug) },
      }
    );
    child.on('close', () => {
      fs.closeSync(log);
      console.log(`done  ${target} (${Math.round((Date.now() - started) / 1000)}s)`);
      resolve();
    });
  });

const main = async () => {
  console.log(`run "${name}": ${targets.length} target(s), ${lanes} lane(s)${compare ? `, comparing with "${compare}"` : ''}`);
  const queue = [...targets];
  await Promise.all(
    Array.from({ length: lanes }, async () => {
      while (queue.length) {
        await runOne(queue.shift());
      }
    })
  );
  console.log('');
  summarize(name, { compare });
  console.log(`\nfailures: node scripts/summary.js ${name} --failures`);
};

main();
