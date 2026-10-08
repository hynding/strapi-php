'use strict';

/**
 * `@strapi/data-transfer` for the suite (jest.config.js maps the import here): the local Strapi
 * providers of packages/core/data-transfer, run in the PHP worker through the bridge by
 * Strapi\ApiTests\DataTransfer. Only `strapi.providers.createLocalStrapiSourceProvider` and
 * `createLocalStrapiDestinationProvider` are provided.
 *
 * A provider cannot be held across bridge calls, so its whole life for one stage (bootstrap, the
 * stage's stream, close) runs in one call: a read stream fetches every item of the stage when it
 * is first read, a write stream sends what was written when it ends. `bootstrap()` and `close()`
 * only resolve the instance (`getStrapi`) and keep the provider's interface.
 */
const { Readable, Writable } = require('stream');

const HELPER = 'Strapi\\ApiTests\\DataTransfer';
const STAGES = ['entities', 'links', 'configuration', 'schemas', 'assets'];
const capitalize = (s) => s.charAt(0).toUpperCase() + s.slice(1);

// options the PHP providers take (`getStrapi` is the worker's instance there)
const serializable = (options) =>
  Object.fromEntries(Object.entries(options).filter(([, v]) => typeof v !== 'function' && v !== undefined));

const helper = (strapi) => {
  if (!strapi || typeof strapi.__class !== 'function') {
    throw new Error('@strapi/data-transfer (api-tests): getStrapi() must return the instance from createStrapiInstance()');
  }
  return strapi.__class(HELPER);
};

const createLocalStrapiSourceProvider = (options) => {
  let strapi;
  const provider = {
    name: 'source::local-strapi',
    type: 'source',
    options,
    async bootstrap() {
      strapi = await options.getStrapi();
    },
    async close() {},
  };
  for (const stage of STAGES) {
    provider[`create${capitalize(stage)}ReadStream`] = () =>
      Readable.from(
        (async function* read() {
          const items = await helper(strapi).readSourceStage(serializable(options), stage);
          yield* items ?? [];
        })()
      );
  }
  return provider;
};

const createLocalStrapiDestinationProvider = (options) => {
  let strapi;
  const provider = {
    name: 'destination::local-strapi',
    type: 'destination',
    options,
    async bootstrap() {
      strapi = await options.getStrapi();
    },
    async close() {},
  };
  for (const stage of STAGES.filter((s) => s !== 'schemas')) {
    provider[`create${capitalize(stage)}WriteStream`] = () => {
      const items = [];
      return new Writable({
        objectMode: true,
        write(chunk, _encoding, callback) {
          items.push(chunk);
          callback();
        },
        final(callback) {
          helper(strapi)
            .writeDestinationStage(serializable(options), stage, items)
            .then(() => callback(), callback);
        },
      });
    };
  }
  return provider;
};

module.exports = {
  strapi: {
    providers: { createLocalStrapiSourceProvider, createLocalStrapiDestinationProvider },
  },
};
