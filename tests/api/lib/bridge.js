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
const path = require('path');
const { execFileSync } = require('child_process');
const { AsyncLocalStorage } = require('async_hooks');
const { Worker } = require('worker_threads');

const CHAIN = Symbol('chain');
/** the event hub's methods that are synchronous upstream (`emit` is not) */
const EVENT_HUB_SYNC = new Set([
  'on', 'once', 'off', 'addListener', 'removeListener', 'removeAllListeners',
  'subscribe', 'unsubscribe', 'removeAllSubscribers', 'destroy',
]);
/** whether a chain ending in a call was sent already (awaited, or dispatched at once) */
const STARTED = Symbol('started');
/** assigning it a list of steps moves a proxy onto them (a call dispatched and kept as a handle) */
const REBASE = Symbol('rebase');

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

/** A schema built with zod v4 (`z` of @strapi/utils or `zod/v4`). */
const isZodSchema = (value) =>
  value !== null && typeof value === 'object' && !value[CHAIN] && typeof value._zod === 'object' && typeof value.safeParse === 'function';

/**
 * JSON form of an argument. With `hooks` (a callback server), a function of this process becomes
 * `{ "$callback": { url, id } }` (a Strapi\ApiTests\Callback in the worker, calling it back here)
 * and a zod schema `{ "$zod": ... }` (a Strapi\ApiTests\RemoteZod, parsing here).
 */
const encodeArg = (value, hooks = null) => {
  if (value && (typeof value === 'object' || typeof value === 'function') && value[CHAIN]) {
    return { $ref: value[CHAIN] };
  }
  if (Array.isArray(value)) return value.map((v) => encodeArg(v, hooks));
  if (value instanceof Date) return value.toISOString();
  if (hooks && isZodSchema(value)) return hooks.zod(value);
  if (typeof value === 'function') {
    if (!hooks) throw new Error('api-tests bridge: functions cannot be sent to the PHP instance');
    return hooks.callback(value);
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value)
        .filter(([, v]) => v !== undefined)
        .map(([k, v]) => [k, encodeArg(v, hooks)])
    );
  }
  return value;
};

/**
 * knex's `schema.createTable(name, (t) => { ... })`: the table callback runs here against a
 * recorder and the worker builds the table from its calls (Strapi\ApiTests\KnexSchema):
 * `[{ method: 'integer', args: ['a'], chain: [['notNullable', []]] }, ...]`.
 */
const recordTable = (build) => {
  const calls = [];
  const table = new Proxy(
    {},
    {
      get: (_, method) => (...args) => {
        const call = { method, args, chain: [] };
        calls.push(call);
        const column = new Proxy(
          {},
          {
            get: (__, modifier) => (...modifierArgs) => {
              call.chain.push([modifier, modifierArgs]);
              return column;
            },
          }
        );
        return column;
      },
    }
  );
  build(table);
  return calls;
};

/** Whether an argument holds a function of this process (not a proxy), or a zod schema. */
const holdsFunction = (value, seen = new Set()) => {
  if (!value || (typeof value !== 'object' && typeof value !== 'function') || value[CHAIN] || seen.has(value)) {
    return false;
  }
  if (typeof value === 'function' || isZodSchema(value)) return true;
  if (value instanceof Date) return false;
  seen.add(value);
  return Object.values(value).some((v) => holdsFunction(v, seen));
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
 * One call of a function of this process by the worker. While the function runs, the worker waits
 * for its answer, so a call the function makes to the instance (`next()`, `strapi.documents(uid)`)
 * cannot go to the worker over HTTP: lib/callback-thread.js queues it and hands it to the worker as
 * the answer to its pending request, in order. A synchronous call (`strapi.db.metadata.get(uid)`)
 * blocks this thread until the callback thread writes its reply to the shared buffer.
 */
class CallSession {
  constructor(id, thread, shared) {
    this.id = id;
    this.thread = thread;
    this.shared = shared;
    this.outcome = null; // the function's result or error, once it settled
    this.pending = new Map(); // reqId → resolve
    this.nextReq = 1;
  }

  /** a call to the instance from inside the function: resolves with the bridge's response body */
  rpc(steps) {
    if (this.outcome) return null; // settled: the worker no longer listens
    const reqId = this.nextReq++;
    return new Promise((resolve) => {
      this.pending.set(reqId, resolve);
      this.thread.postMessage({ type: 'rpc', session: this.id, reqId, steps, sync: false });
    });
  }

  /** the same, blocking this thread: the bridge's response body, or null once the call is over */
  rpcSync(steps) {
    if (this.outcome) return null;
    const { state, bytes } = this.shared;
    Atomics.store(state, 0, 0);
    this.thread.postMessage({ type: 'rpc', session: this.id, reqId: this.nextReq++, steps, sync: true });
    if (Atomics.wait(state, 0, 0, 120000) === 'timed-out') {
      throw new Error('api-tests bridge: no answer from the worker to a synchronous call');
    }
    return JSON.parse(Buffer.from(bytes.slice(0, Atomics.load(state, 1))).toString('utf8'));
  }

  answer(reqId, reply) {
    const resolve = this.pending.get(reqId);
    this.pending.delete(reqId);
    if (resolve) resolve(reply);
  }

  settle(outcome) {
    let message;
    try {
      // JSON, as the worker reads it: proxies on values the worker handed over go back as their handle
      message = JSON.parse(JSON.stringify(outcome));
    } catch (error) {
      message = { error: { name: 'Error', message: `api-tests bridge: the function's result cannot be sent (${error.message})` } };
    }
    this.outcome = message;
    this.thread.postMessage({ type: 'settle', session: this.id, outcome: message });
  }
}

/**
 * The callback server the PHP worker calls back while it serves a request (lib/callback-thread.js,
 * in a worker thread): a function of this process passed to the instance (a listener, a condition
 * handler, a document-service middleware, a transaction callback), or a jest mock installed on a
 * remote object (`jest.spyOn(strapi.plugin('email').service('email'), 'send')`, Strapi\ApiTests\Spy),
 * runs here, in the test process, when the PHP code calls it. The worker waits for the answer; Node
 * is free to run it because the test is awaiting the worker's HTTP response. Calls the function
 * makes to the instance meanwhile go through the same request ({@link CallSession}).
 */
const startCallbackServer = () =>
  new Promise((resolve, reject) => {
    const buffer = new SharedArrayBuffer(8 + 32 * 1024 * 1024);
    const shared = { state: new Int32Array(buffer, 0, 2), bytes: new Uint8Array(buffer, 8) };
    const thread = new Worker(path.join(__dirname, 'callback-thread.js'), { workerData: { buffer } });
    thread.unref();

    const callbacks = new Map();
    const sessions = new Map();
    // what functions threw, by token: the error that reaches the test is that very value
    const thrown = new Map();
    const context = new AsyncLocalStorage();
    let nextId = 1;
    let nextToken = 1;

    thread.on('error', reject);
    thread.on('message', (message) => {
      if (message.type === 'listening') {
        resolve({
          url: message.url,
          register(fn) {
            const id = nextId++;
            callbacks.set(id, fn);
            return id;
          },
          /** the value a function threw, for the token of an error that came back through the worker */
          thrown: (token) => {
            const has = thrown.has(token);
            const value = thrown.get(token);
            thrown.delete(token);
            return { has, value };
          },
          /** the call of a function of this process the current code runs in, if any */
          session: () => {
            const session = context.getStore();
            return session && !session.outcome ? session : null;
          },
          close: () => thread.terminate(),
        });
        return;
      }

      if (message.type === 'reply') {
        const session = sessions.get(message.session);
        if (!session) return;
        session.answer(message.reqId, message.reply);
        if (session.outcome && session.pending.size === 0) sessions.delete(session.id);
        return;
      }

      if (message.type === 'call') {
        const session = new CallSession(message.session, thread, shared);
        sessions.set(session.id, session);
        const fn = callbacks.get(message.id);
        const args = message.args;
        context
          .run(session, () =>
            Promise.resolve().then(() => {
              if (!fn) throw new Error(`api-tests bridge: unknown callback ${message.id}`);
              return fn(...args);
            })
          )
          .then(
            // the arguments as the function left them: Strapi\ApiTests\RemoteObject copies the
            // changes back (`file.url = ...` in a replaced upload provider)
            (result) => session.settle({ result: result === undefined ? null : result, args }),
            (error) => {
              const token = nextToken++;
              thrown.set(token, error);
              session.settle({ error: { name: error?.name ?? 'Error', message: error?.message ?? String(error), token } });
            }
          )
          .finally(() => {
            if (session.pending.size === 0) sessions.delete(session.id);
          });
      }
    });
  });

/**
 * @param {string} rpcUrl  e.g. http://127.0.0.1:41234/__api-tests/rpc
 * @param {object} local   synchronous values that override the remote chain at the root
 * @param {object} [callbacks] a {@link startCallbackServer} result, for jest spies on remote methods
 */
const createRemote = (rpcUrl, local = {}, callbacks = null) => {
  // a call made by a function the worker is calling goes back through that call (CallSession): the
  // worker is busy with it. `owner` is the call a proxy was created in (its continuations may run
  // after the context is gone, e.g. a thenable returned by the function and awaited by the server)
  const run = async (steps, owner = null) => {
    const session = owner && !owner.outcome ? owner : callbacks?.session();
    const response = (session && (await session.rpc(steps))) || (await post(rpcUrl, { steps }));
    if (response.error) throw fromWorkerError(response.error);
    // an object without a data form (a transaction object, a closure): a proxy on it, which awaiting
    // does not resolve again
    if (response.handle !== undefined) return make([{ handle: response.handle }], function remote() {}, true);
    if (response.null) return null;
    return response.result === null ? undefined : response.result;
  };

  // an error a function of this process threw comes back as that very value (`wrapInTransaction`
  // throws a Symbol through `strapi.db.transaction()` and catches it by identity)
  const fromWorkerError = (error) => {
    if (callbacks && error.token !== undefined) {
      const { has, value } = callbacks.thrown(error.token);
      if (has) return value;
    }
    return toError(error);
  };

  const runSync = (steps) => {
    // inside a function the worker is calling, through that call (lib/callback-thread.js)
    const session = callbacks?.session();
    const response = (session && session.rpcSync(steps)) || postSync(rpcUrl, { steps });
    if (response.error) throw fromWorkerError(response.error);
    if (response.null) return null;
    return response.result === null ? undefined : response.result;
  };

  // jest spies installed on remote methods: `${JSON.stringify(steps)}#${method}` → mock function
  const spies = new Map();
  const spyKey = (steps, prop) => `${JSON.stringify(steps)}#${prop}`;
  // remote properties replaced by an object of this process (`plugin.provider = { uploadStream() {} }`)
  const assigned = new Set();

  // the worker's arguments to a function of this process: `{ $handle }` (a closure, a service) is
  // a proxy on the value the worker keeps (`next()`); replaced in place, so the reply's `args` are
  // the function's own (Strapi\ApiTests\RemoteObject copies their changes back)
  const fromWorker = (value) => {
    if (Array.isArray(value)) {
      value.forEach((v, i) => {
        value[i] = fromWorker(v);
      });
    } else if (value && typeof value === 'object') {
      const keys = Object.keys(value);
      if (keys.length === 1 && keys[0] === '$handle') return make([{ handle: value.$handle }], function remote() {}, true);
      keys.forEach((k) => {
        value[k] = fromWorker(value[k]);
      });
    }
    return value;
  };
  const toCallback = (fn) => ({ $callback: { url: callbacks.url, id: callbacks.register((...args) => fn(...fromWorker(args))) } });
  // a zod schema parses here, with the test's zod (Strapi\ApiTests\RemoteZod); an object schema
  // goes as its shape, so the instance can extend it (`contentAPI.addInputParams`)
  const toZod = (schema) => {
    const { def, optin, optout } = schema._zod;
    if (def.type === 'object' && schema.shape) {
      return { $zod: { type: 'object', shape: Object.fromEntries(Object.entries(schema.shape).map(([k, v]) => [k, toZod(v)])) } };
    }
    let jsonSchema = {};
    try {
      jsonSchema = require('@strapi/utils').z.toJSONSchema(schema, { io: 'input', unrepresentable: 'any' });
    } catch {
      // not representable: the instance documents it as any value
    }
    const safeParse = (value, absent) => {
      const result = schema.safeParse(absent ? undefined : value);
      return result.success
        ? { success: true, undefined: result.data === undefined, data: result.data === undefined ? null : result.data }
        : { success: false, issues: result.error.issues };
    };
    return {
      $zod: {
        type: def.type,
        optionalIn: optin === 'optional',
        optionalOut: optout === 'optional',
        optional: schema.safeParse(undefined).success,
        nullable: schema.safeParse(null).success,
        jsonSchema,
        safeParse: toCallback(safeParse),
      },
    };
  };
  const hooks = callbacks ? { callback: toCallback, zod: toZod } : null;
  const encode = (value) => encodeArg(value, hooks);

  // a value upstream reads synchronously in place (`strapi.contentTypes[uid].pluginOptions`): its
  // data, fetched at once, behind a proxy that still passes as a reference to the worker's own
  // value (`formatContentType(strapi.contentTypes[uid])` gets the Schema, not a copy)
  const snapshot = (value, steps) => {
    if (value === null || typeof value !== 'object') return value;
    return new Proxy(value, {
      get(target, prop, receiver) {
        if (prop === CHAIN) return steps;
        const v = Reflect.get(target, prop, receiver);
        return typeof prop === 'string' && Object.prototype.hasOwnProperty.call(target, prop) ? snapshot(v, [...steps, { get: prop }]) : v;
      },
    });
  };

  const make = (steps, callableTarget = null, settled = false) => {
    // a function target so the proxy is callable: strapi.service(uid)(...) / findUser(args).
    // A chain that ends in a call is a result, not a callable: an object target keeps
    // `typeof` at 'object', so Jest's `expect(strapi.documents(uid).findMany(..)).rejects`
    // awaits it instead of calling it (it calls any function it is given).
    let pending = null;
    let failure = null;
    const owner = callbacks?.session() ?? null;
    const last = steps[steps.length - 1];
    const target =
      callableTarget ??
      (last && Object.prototype.hasOwnProperty.call(last, 'call') && !Object.prototype.hasOwnProperty.call(last, 'get') ? {} : function remote() {});
    return new Proxy(target, {
      // `jest.spyOn(remote, 'method')` assigns a mock function: the PHP object's method then calls
      // it back (see startCallbackServer); `mockRestore()` deletes it again
      set(target, prop, value) {
        if (prop === REBASE) {
          if (value instanceof Error) failure = value;
          else steps = value;
          return true;
        }
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
        if (prop === STARTED) return pending !== null;
        if (typeof prop === 'string' && spies.has(spyKey(steps, prop))) return spies.get(spyKey(steps, prop));
        if (prop === 'then' || prop === 'catch' || prop === 'finally') {
          // the root itself is not a thenable, nor a value the worker handed back
          if (steps.length === 0 || (settled && steps.length === 1)) return undefined;
          // one request per proxy: Jest reads `then` to check for a promise and again to await
          // it; a second request would leave the first one's rejection unhandled
          if (!pending) {
            pending = failure ? Promise.reject(failure) : run(steps, owner);
            pending.catch(() => {}); // handled by whoever awaits it
          }
          return pending[prop].bind(pending);
        }
        // sent back to the worker as the value it handed over (CallSession reply `args`)
        if (prop === 'toJSON' && steps.length === 1 && steps[0].handle !== undefined) {
          return () => ({ $handle: steps[0].handle });
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
        // `strapi.contentTypes` / `strapi.components` are plain objects upstream, read in place
        // (`strapi.contentTypes[uid].pluginOptions?.i18n?.localized`)
        if (steps.length === 0 && (prop === 'contentTypes' || prop === 'components')) {
          return snapshot(callSync([{ get: prop }]), [{ get: prop }]);
        }
        // `strapi.sessionManager.generateSessionId()` is synchronous upstream (its result is compared as is)
        if (prop === 'generateSessionId' && steps.length === 1 && steps[0].get === 'sessionManager') {
          return (...args) => callSync([...steps, { get: prop }, { call: args }]);
        }
        // `strapi.db.metadata.get(uid)` is synchronous upstream too (tests read
        // `.attributes.createdBy.joinColumn.name` off it and use it as a property key)
        if (prop === 'get' && steps.length === 2 && steps[0].get === 'db' && steps[1].get === 'metadata') {
          return (...args) => callSync([...steps, { get: 'get' }, { call: args }]);
        }
        return make([...steps, { get: prop }]);
      },
      apply(_, __, args) {
        const last = steps[steps.length - 1];
        const prev = steps[steps.length - 2];
        // `strapi.ai.mcp.registerTool(definition)` (synchronous, not awaited upstream): the Zod schemas cross as JSON Schema (rebuilt as
        // PHP Zod by Strapi\ApiTests\McpDefinition) and the handler runs here, called back with the
        // JSON params (`{ args, extra }`); `createHandler` gets no strapi/context of the worker
        if (last && last.get === 'registerTool' && prev && prev.get === 'mcp' && callbacks && args[0] && typeof args[0] === 'object') {
          const { z } = require('@strapi/utils');
          const def = args[0];
          const toJson = (schema, io) => z.toJSONSchema(schema, { target: 'draft-2020-12', io });
          const encoded = {
            $mcpTool: {
              ...Object.fromEntries(Object.entries(def).filter(([, v]) => typeof v !== 'function')),
              inputJsonSchema: def.resolveInputSchema ? toJson(def.resolveInputSchema({}), 'input') : undefined,
              outputJsonSchema: toJson(def.resolveOutputSchema({}), 'output'),
              handler: { $callback: { url: callbacks.url, id: callbacks.register((params) => def.createHandler(root, {})(params)) } },
            },
          };
          // synchronous upstream, and called without await: send it now
          runSync([...steps, { call: [encodeArg(encoded)] }]);
          return undefined;
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
        // a document-service middleware changes `ctx` in place and calls `next()`: the PHP stack
        // takes the changed context as next's argument (Middlewares\MiddlewareManager). No params
        // is PHP's `[]`, upstream's `{}` (`ctx.params.filters = ...` must survive the trip back)
        if (last && last.get === 'use' && prev && prev.get === 'documents' && steps.length === 2 && typeof args[0] === 'function' && !args[0][CHAIN]) {
          const middleware = args[0];
          args = [
            (ctx, next) => {
              if (Array.isArray(ctx.params) && ctx.params.length === 0) ctx.params = {};
              return middleware(ctx, (...rest) => next(...(rest.length > 0 ? rest : [ctx])));
            },
          ];
        }
        if (last && last.get === 'createTable' && prev && prev.get === 'schema' && typeof args[1] === 'function' && !args[1][CHAIN]) {
          args = [args[0], recordTable(args[1])];
        }
        const callSteps = [...steps, { call: args.map(encode) }];
        // handing the instance a function (`strapi.eventHub.on(name, listener)`,
        // `strapi.documents.use(middleware)`) or calling one of the event hub's synchronous methods
        // is synchronous upstream, and rarely awaited: unless the call is awaited right away, send
        // it before anything else (the test's next request) and keep its result in the worker
        const eventHub = prev && prev.get === 'eventHub' && EVENT_HUB_SYNC.has(last.get);
        if (callbacks && (eventHub || args.some((a) => holdsFunction(a)))) {
          // the event hub's `on`/`subscribe` return the unsubscribe function; any other result stays
          // an object, which `expect(result).resolves` awaits instead of calling
          const result = make(callSteps, eventHub ? function remote() {} : null);
          queueMicrotask(() => {
            if (result[STARTED]) return; // awaited: it runs (and may call back) like any other call
            // inside a function the worker is calling: send it through that call, ahead of the
            // function's later calls (`onCommit(() => ...)` in a transaction callback)
            if (callbacks.session()) {
              result.then(
                () => {},
                () => {}
              );
              return;
            }
            try {
              const { $handle } = runSync([...callSteps, { keep: true }]);
              result[REBASE] = [{ handle: $handle }];
            } catch (error) {
              result[REBASE] = error;
            }
          });
          return result;
        }
        return make(callSteps);
      },
    });
  };

  const root = make([]);
  /** A PHP class upstream's helpers require() directly: `classRef('Strapi\\Core\\...').method(...)`. */
  const classRef = (fqcn) => make([{ class: fqcn }]);
  /** Replays steps synchronously (for APIs upstream exposes synchronously, like `strapi.config`). */
  const callSync = (steps) => runSync(steps.map((step) => (step.call ? { call: step.call.map(encode) } : step)));
  return { root, classRef, callSync };
};

/** The recorded steps of a proxy (`undefined` for any other value). */
const chainOf = (value) => (value && (typeof value === 'object' || typeof value === 'function') ? value[CHAIN] : undefined);

module.exports = { createRemote, startCallbackServer, chainOf };
