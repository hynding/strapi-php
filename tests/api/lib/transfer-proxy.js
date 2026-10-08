'use strict';

/**
 * Remote data transfer (`/admin/transfer/runner/{push,pull}`) is a WebSocket the FrankenPHP
 * worker cannot hold: it answers those requests with 501 and a real deployment routes them to
 * `strapi transfer:serve` (packages/core/data-transfer, Strapi\DataTransfer\Utils\Websocket\Server).
 *
 * This is that routing for the suite: a TCP proxy in front of the worker. A connection whose
 * first request is `GET /admin/transfer/runner/...` goes to a `transfer:serve` sidecar of the same
 * app (same env, same database), started on first use; every other connection is piped to the
 * worker byte for byte. `strapi.server.httpServer.address()` returns the proxy's port, so a test
 * that opens `ws://127.0.0.1:${port}/admin/transfer/runner/push` reaches the sidecar.
 */
const { spawn } = require('child_process');
const fs = require('fs');
const net = require('net');
const path = require('path');
const { repoRoot } = require('./paths');

const RUNNER_PREFIX = Buffer.from('GET /admin/transfer/runner/');

const freePort = () =>
  new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.listen(0, '127.0.0.1', () => {
      const { port } = srv.address();
      srv.close(() => resolve(port));
    });
    srv.on('error', reject);
  });

const canConnect = (port) =>
  new Promise((resolve) => {
    const socket = net.connect(port, '127.0.0.1');
    socket.once('connect', () => {
      socket.destroy();
      resolve(true);
    });
    socket.once('error', () => resolve(false));
  });

const startTransferProxy = async ({ appDir, env, upstreamPort }) => {
  let sidecar = null;

  const startSidecar = async () => {
    const port = await freePort();
    const logFile = path.join(appDir, '.tmp', `transfer-serve-${port}.log`);
    fs.mkdirSync(path.dirname(logFile), { recursive: true });
    const log = fs.openSync(logFile, 'a');
    const child = spawn(
      process.env.PHP_BIN || 'php',
      ['-d', 'memory_limit=1G', path.join(repoRoot, 'packages', 'core', 'strapi', 'bin', 'strapi'), 'transfer:serve', '--host', '127.0.0.1', '--port', String(port)],
      { cwd: appDir, env: { ...process.env, ...env }, stdio: ['ignore', log, log] }
    );
    let exited = null;
    // server.js kills everything in this set when the test process exits (and in stopAll)
    const running = (process.__strapiApiTestServers ??= new Set());
    running.add(child);
    child.unref();
    child.on('exit', (code) => {
      exited = code;
      running.delete(child);
    });
    const deadline = Date.now() + 60000;
    while (!(await canConnect(port))) {
      if (exited !== null || Date.now() > deadline) {
        child.kill('SIGKILL');
        const tail = fs.readFileSync(logFile, 'utf8').split('\n').slice(-30).join('\n');
        throw new Error(`strapi transfer:serve did not start (exit ${exited}). Log ${logFile}:\n${tail}`);
      }
      await new Promise((r) => setTimeout(r, 100));
    }
    return { port, child };
  };

  const ensureSidecar = () => {
    sidecar ??= startSidecar().catch((error) => {
      sidecar = null;
      throw error;
    });
    return sidecar;
  };

  const sockets = new Set();
  const server = net.createServer((client) => {
    sockets.add(client);
    client.once('close', () => sockets.delete(client));
    client.once('data', async (first) => {
      client.pause();
      let port = upstreamPort;
      if (first.subarray(0, RUNNER_PREFIX.length).equals(RUNNER_PREFIX)) {
        try {
          ({ port } = await ensureSidecar());
        } catch (error) {
          client.end(`HTTP/1.1 502 Bad Gateway\r\nContent-Type: text/plain\r\nConnection: close\r\n\r\n${error.message}`);
          return;
        }
      }
      const upstream = net.connect(port, '127.0.0.1');
      sockets.add(upstream);
      upstream.once('close', () => sockets.delete(upstream));
      upstream.on('error', () => client.destroy());
      client.on('error', () => upstream.destroy());
      upstream.write(first);
      client.pipe(upstream);
      upstream.pipe(client);
      client.resume();
    });
    client.on('error', () => {});
  });

  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  server.unref();

  const close = async () => {
    for (const socket of sockets) socket.destroy();
    await new Promise((resolve) => server.close(() => resolve()));
    if (sidecar) {
      const started = await sidecar.catch(() => null);
      sidecar = null;
      if (started && started.child.exitCode === null) {
        await new Promise((resolve) => {
          started.child.once('exit', resolve);
          started.child.kill('SIGTERM');
          setTimeout(() => started.child.kill('SIGKILL'), 5000).unref();
        });
      }
    }
  };

  return { port: server.address().port, close };
};

module.exports = { startTransferProxy };
