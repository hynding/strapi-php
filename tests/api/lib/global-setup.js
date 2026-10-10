'use strict';

/**
 * Before the run: a fresh copy of the PHP test app, and upstream's api-tests helpers with the
 * in-process pieces swapped for bridge-backed ones. Every patch must apply, so an upstream change
 * to those lines fails loudly instead of silently testing something else.
 */
const fs = require('fs');
const path = require('path');
const { apiTestsDir, appDir, helpersDir, upstreamDir } = require('./paths');

const copyDir = (from, to) => fs.cpSync(from, to, { recursive: true, dereference: true });

const patch = (file, from, to) => {
  const text = fs.readFileSync(file, 'utf8');
  if (!text.includes(from)) {
    throw new Error(`tests/api global-setup: cannot patch ${file}: upstream changed\n  expected: ${from}`);
  }
  fs.writeFileSync(file, text.split(from).join(to));
};

module.exports = async () => {
  const upstreamHelpers = path.join(upstreamDir, 'packages', 'utils', 'api-tests');
  if (!fs.existsSync(upstreamHelpers)) {
    throw new Error(`Upstream checkout missing at ${upstreamDir}: run tests/api/scripts/setup.sh or set STRAPI_UPSTREAM`);
  }

  // the app: CTB writes schemas into src/, so each run starts from the template
  fs.rmSync(appDir, { recursive: true, force: true });
  copyDir(path.join(apiTestsDir, 'app'), appDir);

  // upstream helpers
  fs.rmSync(helpersDir, { recursive: true, force: true });
  copyDir(upstreamHelpers, helpersDir);
  fs.writeFileSync(path.join(helpersDir, 'strapi.js'), `module.exports = require(${JSON.stringify(path.join(apiTestsDir, 'lib', 'strapi.js'))});\n`);
  patch(
    path.join(helpersDir, 'models.js'),
    "require('../../core/core/src/services/document-service/components')",
    `require(${JSON.stringify(path.join(apiTestsDir, 'lib', 'component-data.js'))})`
  );
  // getModel() is synchronous in-process; across the bridge it has to be awaited
  patch(path.join(helpersDir, 'models.js'), 'const contentType = strapi.getModel(uid);', 'const contentType = await strapi.getModel(uid);');
  // withMockedFetch() mocks the test process' fetch; the instance fetches from the PHP worker
  patch(
    path.join(helpersDir, 'mock-fetch.js'),
    '    await fn();',
    `    await require(${JSON.stringify(path.join(apiTestsDir, 'lib', 'mock-fetch.js'))}).intercept(mockFn, fn);`
  );

  process.env.ENV_PATH = path.join(appDir, '.env');
};
