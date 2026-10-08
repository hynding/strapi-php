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
  globalSetup: path.join(apiTestsDir, 'lib', 'global-setup.js'),
  setupFilesAfterEnv: [path.join(upstreamDir, 'tests', 'setup', 'jest-api.setup.js')],
  moduleNameMapper: {
    '^api-tests/(.*)$': `${helpersDir}/$1`,
  },
  // upstream files live outside rootDir: resolve their imports from our node_modules
  modulePaths: [path.join(apiTestsDir, 'node_modules')],
  transform: {
    '^.+\\.(t|j)s$': ['@swc/jest'],
  },
  transformIgnorePatterns: ['/node_modules/'],
  testTimeout: 60000,
};
