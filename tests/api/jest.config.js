'use strict';

/**
 * Upstream's API suite (strapi/strapi tests/api at the tracked tag), unmodified, against the PHP
 * test app. See README.md.
 */
const path = require('path');
const { apiTestsDir, helpersDir, upstreamDir } = require('./lib/paths');

module.exports = {
  displayName: 'API integration tests (strapi-php)',
  rootDir: apiTestsDir,
  roots: [path.join(upstreamDir, 'tests', 'api')],
  testMatch: ['**/?(*.)+(spec|test).api.(js|ts)'],
  testPathIgnorePatterns: ['/node_modules/', '/ee/'],
  testEnvironment: 'node',
  testSequencer: path.join(apiTestsDir, 'lib', 'sequencer.js'),
  globalSetup: path.join(apiTestsDir, 'lib', 'global-setup.js'),
  setupFilesAfterEnv: [path.join(apiTestsDir, 'lib', 'jest-setup.js'), path.join(upstreamDir, 'tests', 'setup', 'jest-api.setup.js')],
  moduleNameMapper: {
    '^api-tests/(.*)$': `${helpersDir}/$1`,
    // the local Strapi providers of packages/core/data-transfer, run in the worker
    '^@strapi/data-transfer$': path.join(apiTestsDir, 'lib', 'data-transfer.js'),
  },
  // upstream files live outside rootDir: resolve their imports from our node_modules
  modulePaths: [path.join(apiTestsDir, 'node_modules')],
  transform: {
    '^.+\\.(t|j)s$': ['@swc/jest'],
  },
  // upstream's api-tests helpers are CommonJS that babel-jest leaves alone (a module-level arrow's
  // `this` is module.exports, which builder/action-registry.js relies on); SWC would turn it into undefined
  transformIgnorePatterns: ['/node_modules/', `^${helpersDir.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/`],
  testTimeout: 60000,
};
