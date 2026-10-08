'use strict';

/**
 * Replacement for upstream's packages/utils/api-tests/strapi.js. Same exports and options, but
 * the instance is the PHP test app running under FrankenPHP and `strapi` is a bridge proxy
 * (lib/bridge.js): `await strapi.db.query(uid).findMany()` runs in the PHP worker.
 *
 * The `register` / `bootstrap` callbacks run where upstream runs them: the worker pauses before
 * `register()` and again before the modules' bootstrap (STRAPI_API_TESTS_PHASES, see the app's
 * public/index.php) and serves bridge calls until the harness posts the next phase.
 *
 * Differences from upstream, all forced by the process boundary:
 * - `strapi.server.httpServer` is the server's base URL (supertest accepts it), with an
 *   `address()` that returns `{ address, port }`;
 * - `strapi.config.get/set/has` are synchronous like upstream's (a blocking request per call);
 * - a `strapi.log.warn` replaced by the bootstrap callback receives the warnings the worker
 *   logged while bootstrapping, once loading is done (they cannot be streamed back live);
 * - `logLevel` is ignored (the app logs warnings to its FrankenPHP log under .tmp/app/.tmp/).
 */
const _ = require('lodash');
const dotenv = require('dotenv');
const path = require('path');
const http = require('http');
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

const postJson = (url, body) =>
  new Promise((resolve, reject) => {
    const data = Buffer.from(JSON.stringify(body));
    const req = http.request(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Content-Length': data.length } }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        try {
          resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
        } catch (e) {
          reject(e);
        }
      });
    });
    req.on('error', reject);
    req.end(data);
  });

/** Lets the paused worker go on to the next phase of `load()` (see the app's public/index.php). */
const postPhase = (url, phase) =>
  new Promise((resolve, reject) => {
    const data = Buffer.from(JSON.stringify({ phase }));
    const req = http.request(
      `${url}/__api-tests/phase`,
      { method: 'POST', headers: { 'Content-Type': 'application/json', 'Content-Length': data.length } },
      (res) => {
        res.resume();
        res.on('end', () =>
          res.statusCode === 200 ? resolve() : reject(new Error(`api-tests: worker refused phase ${phase} (${res.statusCode})`))
        );
      }
    );
    req.on('error', reject);
    req.end(data);
  });

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
      // upstream tests switch process.env.NODE_ENV (e.g. to 'production') before creating the instance
      NODE_ENV: process.env.NODE_ENV || 'test',
      STRAPI_API_TESTS_PHASES: '1',
      STRAPI_DISABLE_EE: '1',
      STRAPI_TELEMETRY_DISABLED: 'true',
    },
  });

  // a setup that throws never reaches the test's afterAll(strapi.destroy): stop the worker here
  try {
    const httpServer = new String(server.url); // eslint-disable-line no-new-wrappers
    httpServer.address = () => ({ address: '127.0.0.1', family: 'IPv4', port: server.port });

    let instance;
    const local = {
      server: new Proxy(
        {},
        {
          get(_t, prop) {
            // a String object: supertest reads it as a server (`address().port`), and tests that
            // call `strapi.server.httpServer.address()` themselves get the port too
            if (prop === 'httpServer') return httpServer;
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
    const { root, classRef, callSync } = createRemote(`${server.url}/__api-tests/rpc`, local);
    local.__class = classRef;
    // strapi.config is synchronous upstream (`jwt.verify(token, strapi.config.get('admin.auth.secret'))`)
    local.config = {
      get: (...args) => callSync([{ get: 'config' }, { get: 'get' }, { call: args }]),
      set: (...args) => callSync([{ get: 'config' }, { get: 'set' }, { call: args }]),
      has: (...args) => callSync([{ get: 'config' }, { get: 'has' }, { call: args }]),
    };
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
    await postPhase(server.url, 'register');
    if (bootstrap) await bootstrap({ strapi: instance });
    await postPhase(server.url, 'bootstrap');

    // warnings logged while bootstrapping, for a `strapi.log.warn` spy installed by the callback
    const { warnings = [] } = await postJson(`${server.url}/__api-tests/warnings`, {});
    warnings.forEach((message) => instance.log.warn(message));

    global.strapi = instance;

    if (ensureSuperAdmin) {
      // upstream's own utils, through the bridge
      const { createUtils } = require('api-tests/utils');
      await createUtils(instance).createUserIfNotExists({ ...superAdminCredentials });
    }
    return instance;
  } catch (error) {
    await server.stop();
    throw error;
  }
};

module.exports = {
  createStrapiInstance,
  superAdmin: {
    loginInfo: superAdminLoginInfo,
    credentials: superAdminCredentials,
  },
};
