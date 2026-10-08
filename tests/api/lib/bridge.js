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

const toError = (error) => {
  const e = new Error(error.message);
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
 * @param {string} rpcUrl  e.g. http://127.0.0.1:41234/__api-tests/rpc
 * @param {object} local   synchronous values that override the remote chain at the root
 */
const createRemote = (rpcUrl, local = {}) => {
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

  const make = (steps) => {
    // a function target so the proxy is callable: strapi.service(uid)(...) / findUser(args)
    const target = function remote() {};
    return new Proxy(target, {
      get(_, prop) {
        if (prop === CHAIN) return steps;
        if (prop === 'then') {
          if (steps.length === 0) return undefined; // the root itself is not a thenable
          const promise = run(steps);
          return promise.then.bind(promise);
        }
        if (prop === 'catch' || prop === 'finally') {
          const promise = run(steps);
          return promise[prop].bind(promise);
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
        return make([...steps, { get: prop }]);
      },
      apply(_, __, args) {
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

module.exports = { createRemote };
