'use strict';

/**
 * Upstream's `withMockedFetch(mockFn, fn)` (api-tests/mock-fetch.js) swaps `globalThis.fetch` and
 * runs `fn`: that mocks the instance's `strapi.fetch` because the instance runs in the test
 * process. global-setup patches it to run `fn` through `intercept()`, which also hands `mockFn` to
 * the PHP worker (Strapi\ApiTests\MockFetch) for as long as `fn` runs: the worker's `strapi.fetch`
 * calls it back with `(url, options)` and gets the mocked Response as data, or null (`undefined`
 * from `mockFn`) to go to the network.
 */
const intercept = async (mockFn, fn) => {
  const instance = global.strapi;
  if (!instance || typeof instance.__callSync !== 'function') {
    await fn();
    return;
  }

  const handler = async (url, init) => {
    const response = await mockFn(url, init);
    if (response === undefined || response === null) return null;
    return {
      status: response.status,
      statusText: response.statusText,
      headers: Object.fromEntries(response.headers),
      body: Buffer.from(await response.arrayBuffer()).toString('base64'),
    };
  };

  const mock = (method, args) => instance.__callSync([{ class: 'Strapi\\ApiTests\\MockFetch' }, { get: method }, { call: args }]);
  mock('install', [handler]);
  try {
    await fn();
  } finally {
    mock('uninstall', []);
  }
};

module.exports = { intercept };
