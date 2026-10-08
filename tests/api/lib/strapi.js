'use strict';

/**
 * Replacement for upstream's packages/utils/api-tests/strapi.js. Same exports and options, but
 * the instance is the PHP test app running under FrankenPHP and `strapi` is a bridge proxy
 * (lib/bridge.js): `await strapi.db.query(uid).findMany()` runs in the PHP worker.
 *
 * Differences from upstream, all forced by the process boundary:
 * - `bootstrap` / `register` callbacks run after the instance has loaded (they mostly call
 *   `strapi.config.set`, which the worker keeps for the requests that follow);
 * - `strapi.server.httpServer` is the server's base URL (supertest accepts it), with an
 *   `address()` that returns `{ address, port }`;
 * - `logLevel` is ignored (the app logs warnings to its FrankenPHP log under .tmp/app/.tmp/).
 */
const _ = require('lodash');
const dotenv = require('dotenv');
const path = require('path');
const { createRemote } = require('./bridge');
const { startServer } = require('./server');
const { appDir, repoRoot } = require('./paths');

const superAdminCredentials = {
  email: 'admin@strapi.io',
  firstname: 'admin',
  lastname: 'admin',
  password: 'Password123',
};

const superAdminLoginInfo = _.pick(superAdminCredentials, ['email', 'password']);

const createStrapiInstance = async ({
  ensureSuperAdmin = true,
  bypassAuth = true,
  bootstrap,
  register,
  skipDefaultSessionConfig = false,
} = {}) => {
  const envPath = process.env.ENV_PATH || path.join(appDir, '.env');
  const env = dotenv.parse(require('fs').readFileSync(envPath));

  const server = await startServer({
    appDir: path.dirname(envPath),
    env: {
      ...env,
      STRAPI_API_TESTS_BYPASS_AUTH: bypassAuth ? '1' : '0',
      STRAPI_API_TESTS_AUTOLOAD: path.join(repoRoot, 'vendor', 'autoload.php'),
      NODE_ENV: 'test',
      STRAPI_DISABLE_EE: '1',
      STRAPI_TELEMETRY_DISABLED: 'true',
    },
  });

  const httpServer = new String(server.url); // eslint-disable-line no-new-wrappers
  httpServer.address = () => ({ address: '127.0.0.1', family: 'IPv4', port: server.port });

  let instance;
  const local = {
    server: new Proxy(
      {},
      {
        get(_t, prop) {
          if (prop === 'httpServer') return httpServer.toString();
          return instance && root.server[prop];
        },
      }
    ),
    destroy: async () => {
      await server.stop();
    },
    log: { level: 'warn', info() {}, warn() {}, error() {}, debug() {} },
    __url: server.url,
    __logFile: server.logFile,
  };
  const { root, classRef } = createRemote(`${server.url}/__api-tests/rpc`, local);
  local.__class = classRef;
  instance = root;

  if (!skipDefaultSessionConfig) {
    const THIRTY_DAYS_SEC = 30 * 24 * 60 * 60;
    const ONE_DAY_SEC = 24 * 60 * 60;
    if ((await instance.config.get('admin.auth.sessions.maxRefreshTokenLifespan')) == null) {
      await instance.config.set('admin.auth.sessions.maxRefreshTokenLifespan', THIRTY_DAYS_SEC);
    }
    if ((await instance.config.get('admin.auth.sessions.maxSessionLifespan')) == null) {
      await instance.config.set('admin.auth.sessions.maxSessionLifespan', ONE_DAY_SEC);
    }
  }

  if (register) await register({ strapi: instance });
  if (bootstrap) await bootstrap({ strapi: instance });

  global.strapi = instance;

  if (ensureSuperAdmin) {
    // upstream's own utils, through the bridge
    const { createUtils } = require('api-tests/utils');
    await createUtils(instance).createUserIfNotExists({ ...superAdminCredentials });
  }

  return instance;
};

module.exports = {
  createStrapiInstance,
  superAdmin: {
    loginInfo: superAdminLoginInfo,
    credentials: superAdminCredentials,
  },
};
