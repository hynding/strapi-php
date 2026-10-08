'use strict';

/**
 * Starts the PHP test app under FrankenPHP worker mode (one worker) and stops it.
 */
const { spawn } = require('child_process');
const fs = require('fs');
const net = require('net');
const path = require('path');
const http = require('http');

const freePort = () =>
  new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.listen(0, '127.0.0.1', () => {
      const { port } = srv.address();
      srv.close(() => resolve(port));
    });
    srv.on('error', reject);
  });

const ping = (url) =>
  new Promise((resolve) => {
    const req = http.get(url, (res) => {
      res.resume();
      resolve(true);
    });
    req.on('error', () => resolve(false));
    req.setTimeout(2000, () => {
      req.destroy();
      resolve(false);
    });
  });

// a test that fails in beforeAll never calls strapi.destroy(): never leave a worker behind
const running = new Set();
process.on('exit', () => {
  for (const child of running) child.kill('SIGKILL');
});

const startServer = async ({ appDir, env = {} }) => {
  const frankenphp = process.env.FRANKENPHP_BIN || path.join(__dirname, '..', '.bin', 'frankenphp');
  if (!fs.existsSync(frankenphp)) {
    throw new Error(`FrankenPHP not found at ${frankenphp}: run tests/api/scripts/setup.sh or set FRANKENPHP_BIN`);
  }
  const port = await freePort();
  const url = `http://127.0.0.1:${port}`;
  const logFile = path.join(appDir, '.tmp', `frankenphp-${port}.log`);
  fs.mkdirSync(path.dirname(logFile), { recursive: true });
  const log = fs.openSync(logFile, 'a');

  const child = spawn(
    frankenphp,
    ['php-server', '--listen', `127.0.0.1:${port}`, '--root', path.join(appDir, 'public'), '--worker', `${path.join(appDir, 'public', 'index.php')},1`],
    {
      cwd: appDir,
      env: { ...process.env, ...env, PORT: String(port), HOST: '127.0.0.1' },
      stdio: ['ignore', log, log],
    }
  );

  let exited = null;
  running.add(child);
  child.unref();
  child.on('exit', (code) => {
    exited = code;
    running.delete(child);
  });

  const deadline = Date.now() + 60000;
  // the worker answers once bootstrap is done; /_health is a core route
  while (!(await ping(`${url}/_health`))) {
    if (exited !== null || Date.now() > deadline) {
      const tail = fs.readFileSync(logFile, 'utf8').split('\n').slice(-30).join('\n');
      child.kill('SIGKILL');
      throw new Error(`FrankenPHP did not start (exit ${exited}). Log ${logFile}:\n${tail}`);
    }
    await new Promise((r) => setTimeout(r, 100));
  }

  const stop = () =>
    new Promise((resolve) => {
      if (exited !== null) return resolve();
      child.once('exit', () => resolve());
      child.kill('SIGTERM');
      setTimeout(() => child.kill('SIGKILL'), 5000).unref();
    });

  return { url, port, logFile, stop };
};

module.exports = { startServer };
