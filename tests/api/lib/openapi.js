'use strict';

/**
 * `@strapi/openapi` as upstream suites import it (core/strapi/api/openapi-extra-params):
 * `generate(strapi, options)` is synchronous upstream; it runs in the worker
 * (Strapi\Openapi\Exports::generate) through a blocking bridge call.
 */
const generate = (strapi, options = {}) =>
  strapi.__callSync([{ class: 'Strapi\\Openapi\\Exports' }, { get: 'generate' }, { call: [{ $ref: [] }, options] }]);

module.exports = { generate };
