'use strict';

/**
 * Reads the Jest JSON reports of a run (.results/<run>/<dir>.json, written by scripts/run.js).
 *
 *   node scripts/summary.js <run>                      # passed / (passed + failed) per directory
 *   node scripts/summary.js <run> --failures           # plus every failing test, grouped by file
 *   node scripts/summary.js <run> --compare <other>    # plus what changed since <other>
 *
 * "passed / (passed + failed)" leaves out upstream's skipped and todo tests, as README.md's
 * status table does.
 */

const fs = require('fs');
const path = require('path');

const resultsDir = path.resolve(__dirname, '..', '.results');

/** @returns {Record<string, object>} directory slug → Jest JSON report */
const readRun = (run) => {
  const dir = path.join(resultsDir, run);
  if (!fs.existsSync(dir)) {
    throw new Error(`No run named "${run}" in ${resultsDir}`);
  }
  const reports = {};
  for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.json')).sort()) {
    try {
      reports[file.slice(0, -5)] = JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'));
    } catch {
      // a directory still running, or one whose Jest process died before writing its report
    }
  }
  return reports;
};

const shortName = (file) => file.split('/tests/api/').pop();

/** @returns {Map<string, string>} "file :: test" → status */
const statuses = (report) => {
  const map = new Map();
  for (const suite of report.testResults) {
    for (const test of suite.assertionResults) {
      map.set(`${shortName(suite.name)} :: ${test.fullName}`, test.status);
    }
  }
  return map;
};

const summarize = (run, { failures = false, compare = null, out = console.log } = {}) => {
  const reports = readRun(run);
  const previous = compare ? readRun(compare) : {};
  const total = { passed: 0, counted: 0 };
  const rows = [];

  for (const [slug, report] of Object.entries(reports)) {
    const passed = report.numPassedTests;
    const counted = passed + report.numFailedTests;
    total.passed += passed;
    total.counted += counted;
    const before = previous[slug] ? previous[slug].numPassedTests : null;
    const delta = before === null ? '' : passed === before ? '' : ` (${passed > before ? '+' : ''}${passed - before})`;
    rows.push(`${slug.padEnd(28)} ${String(passed).padStart(5)} / ${counted}${delta}`);
  }

  out(rows.join('\n'));
  out(`${'total'.padEnd(28)} ${String(total.passed).padStart(5)} / ${total.counted}` +
    (total.counted ? ` (${((100 * total.passed) / total.counted).toFixed(1)}%)` : ''));

  if (compare) {
    const lost = [];
    const gained = [];
    for (const [slug, report] of Object.entries(reports)) {
      if (!previous[slug]) continue;
      const now = statuses(report);
      const then = statuses(previous[slug]);
      for (const [name, status] of now) {
        if (then.get(name) === 'passed' && status !== 'passed') lost.push(name);
        if (then.get(name) !== 'passed' && then.has(name) && status === 'passed') gained.push(name);
      }
    }
    out(`\nSince ${compare}: ${gained.length} now pass, ${lost.length} no longer pass`);
    for (const name of lost) out(`  - ${name}`);
    for (const name of gained) out(`  + ${name}`);
  }

  if (failures) {
    out('\nFailing tests');
    for (const report of Object.values(reports)) {
      for (const suite of report.testResults) {
        const failed = suite.assertionResults.filter((t) => t.status === 'failed');
        if (failed.length === 0 && !(suite.message && suite.assertionResults.length === 0)) continue;
        out(`\n${shortName(suite.name)} (${failed.length})`);
        if (failed.length === 0) out(`  suite failed: ${suite.message.split('\n').find(Boolean)}`);
        for (const test of failed) {
          const reason = (test.failureMessages[0] || '').split('\n').find((l) => l.trim()) || '';
          out(`  ${test.fullName}\n      ${reason.trim().slice(0, 160)}`);
        }
      }
    }
  }

  return total;
};

module.exports = { summarize, readRun, resultsDir };

if (require.main === module) {
  const args = process.argv.slice(2);
  const run = args.find((a) => !a.startsWith('--') && args[args.indexOf(a) - 1] !== '--compare');
  if (!run) {
    console.error('usage: node scripts/summary.js <run> [--failures] [--compare <other run>]');
    const runs = fs.existsSync(resultsDir) ? fs.readdirSync(resultsDir) : [];
    if (runs.length) console.error(`runs: ${runs.join(', ')}`);
    process.exit(2);
  }
  const i = args.indexOf('--compare');
  summarize(run, { failures: args.includes('--failures'), compare: i >= 0 ? args[i + 1] : null });
}
