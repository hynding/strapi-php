'use strict';

/**
 * The callback server of lib/bridge.js, in a worker thread so that it can answer the PHP worker while
 * the test's thread is blocked on a synchronous call (`strapi.db.metadata.get(uid)` inside a
 * `strapi.db.transaction()` callback).
 *
 * The PHP worker POSTs `{ id, args }` to call a function of the test process (Strapi\ApiTests\Callback)
 * and waits for the answer. While the function runs, the calls it makes to the instance are queued
 * here per call ("session") and handed to the PHP worker one at a time as the answer to its pending
 * request (`{ session, steps }`); it replays one and posts the result back (`{ session, reply }`).
 * When the function settles and no call is left, the answer is its outcome (`{ result, args }` or
 * `{ error }`). A synchronous call's reply is written to the shared buffer, where the blocked test
 * thread reads it; any other reply goes back as a message.
 *
 * Messages from the test thread: `{ type: 'rpc', session, reqId, steps, sync }`, `{ type: 'settle', session, outcome }`.
 * To the test thread: `{ type: 'listening', url }`, `{ type: 'call', session, id, args }`,
 * `{ type: 'reply', session, reqId, reply }`.
 */
const http = require('http');
const { parentPort, workerData } = require('worker_threads');

const { buffer } = workerData; // Int32 [state, length] then the reply's bytes
const state = new Int32Array(buffer, 0, 2);
const bytes = new Uint8Array(buffer, 8);

const sessions = new Map();
let nextSession = 1;

const send = (res, body) => {
  const data = Buffer.from(JSON.stringify(body));
  res.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': data.length });
  res.end(data);
};

/** answers the PHP worker's pending request, if there is something to tell it */
const flush = (session) => {
  if (!session.res || session.inflight) return;
  if (session.outbox.length > 0) {
    session.inflight = session.outbox.shift();
    const res = session.res;
    session.res = null;
    send(res, { session: session.id, steps: session.inflight.steps });
    return;
  }
  if (session.outcome) {
    const res = session.res;
    session.res = null;
    sessions.delete(session.id);
    send(res, session.outcome);
  }
};

const replySync = (reply) => {
  const data = Buffer.from(JSON.stringify(reply));
  if (data.length > bytes.length) {
    const error = Buffer.from(JSON.stringify({ error: { name: 'Error', message: `api-tests bridge: reply too large (${data.length} bytes)` } }));
    bytes.set(error);
    Atomics.store(state, 1, error.length);
  } else {
    bytes.set(data);
    Atomics.store(state, 1, data.length);
  }
  Atomics.store(state, 0, 1);
  Atomics.notify(state, 0);
};

const server = http.createServer((req, res) => {
  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', () => {
    let body;
    try {
      body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    } catch (error) {
      send(res, { error: { name: 'Error', message: `api-tests bridge: bad callback request (${error.message})` } });
      return;
    }

    if (body.session === undefined) {
      const session = { id: nextSession++, res, outbox: [], inflight: null, outcome: null };
      sessions.set(session.id, session);
      parentPort.postMessage({ type: 'call', session: session.id, id: body.id, args: body.args ?? [] });
      return;
    }

    const session = sessions.get(body.session);
    if (!session || !session.inflight) {
      send(res, { error: { name: 'Error', message: `api-tests bridge: unknown callback session ${body.session}` } });
      return;
    }
    session.res = res;
    const { reqId, sync } = session.inflight;
    session.inflight = null;
    if (sync) replySync(body.reply);
    else parentPort.postMessage({ type: 'reply', session: session.id, reqId, reply: body.reply });
    flush(session);
  });
});

parentPort.on('message', (message) => {
  const session = sessions.get(message.session);
  if (!session) {
    // the call is over: answer a late synchronous request so the test thread does not hang
    if (message.type === 'rpc' && message.sync) replySync(null);
    return;
  }
  if (message.type === 'rpc') session.outbox.push({ reqId: message.reqId, steps: message.steps, sync: message.sync });
  if (message.type === 'settle') session.outcome = message.outcome;
  flush(session);
});

server.listen(0, '127.0.0.1', () => {
  parentPort.postMessage({ type: 'listening', url: `http://127.0.0.1:${server.address().port}/` });
});
