'use strict';

/**
 * Client of Strapi\ApiTests\Bridge: turns `strapi.<anything>` into a recorded chain of
 * `{ get }` / `{ call }` steps that the PHP worker replays when the expression is awaited.
 *
 *   await strapi.db.query('admin::user').findOne({ where })
 *   // POST /__api-tests/rpc {"steps":[{"get":"db"},{"get":"query"},{"call":["admin::user"]},
 *   //                                   {"get":"findOne"},{"call":[{"where":...}]}]}
 *
 * A proxy passed as an argument is sent as `{ "$ref": steps }` and resolved on the PHP side.
 * Values that must be known synchronously (the HTTP server address) come from `local`.
 */
const http = require('http');
const { execFileSync } = require('child_process');

const CHAIN = Symbol('chain');

const post = (url, body) =>
  new Promise((resolve, reject) => {
    const data = Buffer.from(JSON.stringify(body));
    const req = http.request(
      url,
      { method: 'POST', headers: { 'Content-Type': 'application/json', 'Content-Length': data.length } },
      (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const text = Buffer.concat(chunks).toString('utf8');
          let json;
          try {
            json = JSON.parse(text);
          } catch {
            reject(new Error(`api-tests bridge: non-JSON response (${res.statusCode}): ${text.slice(0, 500)}`));
            return;
          }
          resolve(json);
        });
      }
    );
    req.on('error', reject);
    req.end(data);
  });

const encodeArg = (value) => {
  if (value && (typeof value === 'object' || typeof value === 'function') && value[CHAIN]) {
    return { $ref: value[CHAIN] };
  }
  if (Array.isArray(value)) return value.map(encodeArg);
  if (value instanceof Date) return value.toISOString();
  if (typeof value === 'function') {
    throw new Error('api-tests bridge: functions cannot be sent to the PHP instance');
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value)
        .filter(([, v]) => v !== undefined)
        .map(([k, v]) => [k, encodeArg(v)])
    );
  }
  return value;
};

// upstream tests check error classes (`rejects.toThrow(errors.ValidationError)`): rebuild a PHP
// ApplicationError as the matching @strapi/utils class, the same module instance the test imports
let utilsErrors = null;
const errorClass = (name) => {
  if (utilsErrors === null) {
    try {
      utilsErrors = require('@strapi/utils').errors || {};
    } catch {
      utilsErrors = {};
    }
  }
  const Cls = Object.prototype.hasOwnProperty.call(utilsErrors, name) ? utilsErrors[name] : null;
  return typeof Cls === 'function' ? Cls : null;
};

const toError = (error) => {
  const Cls = errorClass(error.name);
  let e;
  try {
    e = Cls ? new Cls(error.message, error.details) : new Error(error.message);
  } catch {
    e = new Error(error.message);
  }
  e.message = error.message;
  e.name = error.name;
  if (error.details !== undefined) e.details = error.details;
  if (error.status !== undefined) e.status = error.status;
  e.phpClass = error.class;
  e.phpLocation = error.at;
  return e;
};

/**
 * Synchronous variant of `post`, for values upstream reads synchronously in-process and then
 * uses as primitives (a proxy coerced to a string or number, e.g. a column name taken from
 * `strapi.db.metadata.get(uid)` and used as a property key). Runs the request in a child process.
 */
const postSync = (url, body) => {
  const script = `
    const http = require('http');
    let input = '';
    process.stdin.on('data', (c) => (input += c)).on('end', () => {
      const data = Buffer.from(input);
      const req = http.request(process.argv[1], { method: 'POST', headers: { 'Content-Type': 'application/json', 'Content-Length': data.length } }, (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c)).on('end', () => process.stdout.write(Buffer.concat(chunks)));
      });
      req.on('error', (e) => { process.stderr.write(String(e)); process.exit(1); });
      req.end(data);
    });`;
  const text = execFileSync(process.execPath, ['-e', script, url], { input: JSON.stringify(body) }).toString('utf8');
  return JSON.parse(text);
};

/**
 * A local HTTP server the PHP worker calls back while it serves a request: a jest mock installed
 * on a remote object (`jest.spyOn(strapi.plugin('email').service('email'), 'send')`) runs here, in
 * the test process, when the PHP code calls that method (Strapi\\ApiTests\\Spy). The worker waits
 * for the answer; Node is free to serve it because the test is awaiting the worker's HTTP response.
 */
const startCallbackServer = () =>
  new Promise((resolve, reject) => {
    const callbacks = new Map();
    let nextId = 1;
    const server = http.createServer((req, res) => {
      const chunks = [];
      req.on('data', (c) => chunks.push(c));
      req.on('end', async () => {
        const reply = (body) => {
          const data = Buffer.from(JSON.stringify(body));
          res.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': data.length });
          res.end(data);
        };
        try {
          const { id, args = [] } = JSON.parse(Buffer.concat(chunks).toString('utf8'));
          const fn = callbacks.get(id);
          if (!fn) throw new Error(`api-tests bridge: unknown callback ${id}`);
          const result = await fn(...args);
          // the arguments as the function left them: Strapi\ApiTests\RemoteObject copies the
          // changes back (`file.url = ...` in a replaced upload provider)
          reply({ result: result === undefined ? null : result, args });
        } catch (error) {
          reply({ error: { name: error?.name ?? 'Error', message: error?.message ?? String(error) } });
        }
      });
    });
    server.on('error', reject);
    server.listen(0, '127.0.0.1', () => {
      server.unref();
      const { port } = server.address();
      resolve({
        url: `http://127.0.0.1:${port}/`,
        register(fn) {
          const id = nextId++;
          callbacks.set(id, fn);
          return id;
        },
        close: () => new Promise((r) => server.close(() => r())),
      });
    });
  });

/**
 * @param {string} rpcUrl  e.g. http://127.0.0.1:41234/__api-tests/rpc
 * @param {object} local   synchronous values that override the remote chain at the root
 * @param {object} [callbacks] a {@link startCallbackServer} result, for jest spies on remote methods
 */
const createRemote = (rpcUrl, local = {}, callbacks = null) => {
  const run = async (steps) => {
    const response = await post(rpcUrl, { steps });
    if (response.error) throw toError(response.error);
    if (response.null) return null;
    return response.result === null ? undefined : response.result;
  };

  const runSync = (steps) => {
    const response = postSync(rpcUrl, { steps });
    if (response.error) throw toError(response.error);
    if (response.null) return null;
    return response.result === null ? undefined : response.result;
  };

  // jest spies installed on remote methods: `${JSON.stringify(steps)}#${method}` → mock function
  const spies = new Map();
  const spyKey = (steps, prop) => `${JSON.stringify(steps)}#${prop}`;
  // remote properties replaced by an object of this process (`plugin.provider = { uploadStream() {} }`)
  const assigned = new Set();

  const make = (steps) => {
    // a function target so the proxy is callable: strapi.service(uid)(...) / findUser(args).
    // A chain that ends in a call is a result, not a callable: an object target keeps
    // `typeof` at 'object', so Jest's `expect(strapi.documents(uid).findMany(..)).rejects`
    // awaits it instead of calling it (it calls any function it is given).
    let pending = null;
    const last = steps[steps.length - 1];
    const target = last && Object.prototype.hasOwnProperty.call(last, 'call') && !Object.prototype.hasOwnProperty.call(last, 'get') ? {} : function remote() {};
    return new Proxy(target, {
      // `jest.spyOn(remote, 'method')` assigns a mock function: the PHP object's method then calls
      // it back (see startCallbackServer); `mockRestore()` deletes it again
      set(target, prop, value) {
        const local = () => Reflect.set(target, prop, value); // not reflected in PHP
        // an object with methods assigned to a remote property (a stub upload provider): the
        // property becomes a Strapi\ApiTests\RemoteObject whose methods call these back
        if (
          typeof prop === 'string' && steps.length > 0 && callbacks && value && typeof value === 'object' &&
          !Array.isArray(value) && !value[CHAIN] && Object.values(value).some((v) => typeof v === 'function')
        ) {
          const methods = {};
          const values = {};
          for (const [k, v] of Object.entries(value)) {
            if (typeof v === 'function' && !v[CHAIN]) methods[k] = { $callback: { url: callbacks.url, id: callbacks.register(v) } };
            else if (typeof v !== 'function') values[k] = encodeArg(v);
          }
          runSync([...steps, { assign: { prop, methods, values } }]);
          assigned.add(spyKey(steps, prop));
          return true;
        }
        if (typeof prop !== 'string' || steps.length === 0 || typeof value !== 'function' || !callbacks) {
          return local();
        }
        if (value[CHAIN] && JSON.stringify(value[CHAIN]) === JSON.stringify([...steps, { get: prop }])) {
          // restoring the original remote method (or property)
          if (spies.delete(spyKey(steps, prop))) runSync([...steps, { unspy: prop }]);
          if (assigned.delete(spyKey(steps, prop))) runSync([...steps, { restore: prop }]);
          return true;
        }
        try {
          const id = callbacks.register(value);
          runSync([...steps, { spy: { method: prop, url: callbacks.url, id } }]);
        } catch {
          // only methods of registered services can be replaced in PHP
          return local();
        }
        spies.set(spyKey(steps, prop), value);
        return true;
      },
      deleteProperty(target, prop) {
        if (typeof prop === 'string' && spies.has(spyKey(steps, prop))) {
          spies.delete(spyKey(steps, prop));
          runSync([...steps, { unspy: prop }]);
          return true;
        }
        return Reflect.deleteProperty(target, prop);
      },
      get(_, prop) {
        if (prop === CHAIN) return steps;
        if (typeof prop === 'string' && spies.has(spyKey(steps, prop))) return spies.get(spyKey(steps, prop));
        if (prop === 'then' || prop === 'catch' || prop === 'finally') {
          if (steps.length === 0) return undefined; // the root itself is not a thenable
          // one request per proxy: Jest reads `then` to check for a promise and again to await
          // it; a second request would leave the first one's rejection unhandled
          if (!pending) {
            pending = run(steps);
            pending.catch(() => {}); // handled by whoever awaits it
          }
          return pending[prop].bind(pending);
        }
        if (prop === Symbol.toPrimitive) {
          // used as a primitive (property key, template string, arithmetic): resolve it now
          return () => {
            const value = runSync(steps);
            return value !== null && typeof value === 'object' ? JSON.stringify(value) : value;
          };
        }
        if (typeof prop === 'symbol') return undefined;
        if (steps.length === 0 && Object.prototype.hasOwnProperty.call(local, prop)) {
          return local[prop];
        }
        // `strapi.plugin(name).config(path)` is synchronous upstream (tests compare its result
        // directly); awaiting it still works since it returns the value itself
        if (prop === 'config' && steps.length === 2 && steps[0].get === 'plugin' && steps[1].call) {
          return (...args) => callSync([...steps, { get: 'config' }, { call: args }]);
        }
        // `strapi.db.metadata.get(uid)` is synchronous upstream too (tests read
        // `.attributes.createdBy.joinColumn.name` off it and use it as a property key)
        if (prop === 'get' && steps.length === 2 && steps[0].get === 'db' && steps[1].get === 'metadata') {
          return (...args) => callSync([...steps, { get: 'get' }, { call: args }]);
        }
        return make([...steps, { get: prop }]);
      },
      apply(_, __, args) {
        // `strapi.db.transaction(cb)`: a JS callback cannot run in the PHP worker. Run it here,
        // without a database transaction (no rollback: what the callback writes is kept).
        let pending = null;
    const last = steps[steps.length - 1];
        const prev = steps[steps.length - 2];
        if (typeof args[0] === 'function' && last && last.get === 'transaction' && prev && prev.get === 'db') {
          const noop = () => {};
          return Promise.resolve().then(() =>
            args[0]({ trx: undefined, commit: noop, rollback: noop, onCommit: noop, onRollback: noop })
          );
        }
        // `strapi.db.lifecycles.subscribe(subscriber)` is synchronous upstream and returns the
        // unsubscribe function: subscribe now, with the subscriber's functions called back in this
        // process (Strapi\ApiTests\Callback), and hand back a function that unsubscribes
        if (last && last.get === 'subscribe' && prev && prev.get === 'lifecycles' && callbacks) {
          const toCallback = (fn) => ({ $callback: { url: callbacks.url, id: callbacks.register(fn) } });
          const subscriber =
            typeof args[0] === 'function' && !args[0][CHAIN]
              ? toCallback(args[0])
              : Object.fromEntries(
                  Object.entries(args[0] ?? {}).map(([k, v]) => [k, typeof v === 'function' && !v[CHAIN] ? toCallback(v) : v])
                );
          const handle = runSync([...steps, { call: [encodeArg(subscriber)] }, { keep: true }]);
          return () => runSync([{ handle: handle.$handle }, { call: [] }]);
        }
        return make([...steps, { call: args.map(encodeArg) }]);
      },
    });
  };

  const root = make([]);
  /** A PHP class upstream's helpers require() directly: `classRef('Strapi\\Core\\...').method(...)`. */
  const classRef = (fqcn) => make([{ class: fqcn }]);
  /** Replays steps synchronously (for APIs upstream exposes synchronously, like `strapi.config`). */
  const callSync = (steps) => runSync(steps.map((step) => (step.call ? { call: step.call.map(encodeArg) } : step)));
  return { root, classRef, callSync };
};

module.exports = { createRemote, startCallbackServer };
