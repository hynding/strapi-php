'use strict';

const path = require('path');

const repoRoot = path.resolve(__dirname, '..', '..', '..');
const apiTestsDir = path.resolve(__dirname, '..');
const tmpDir = path.join(apiTestsDir, '.tmp');

module.exports = {
  repoRoot,
  apiTestsDir,
  tmpDir,
  /** upstream strapi/strapi checkout at the tracked tag (scripts/setup.sh) */
  upstreamDir: process.env.STRAPI_UPSTREAM
    ? path.resolve(process.env.STRAPI_UPSTREAM)
    : path.join(apiTestsDir, '.upstream'),
  /** the PHP test app the suite runs against (copied from tests/api/app before each run) */
  appDir: path.join(tmpDir, 'app'),
  /** upstream packages/utils/api-tests with strapi.js swapped for ours */
  helpersDir: path.join(tmpDir, 'api-tests'),
};
