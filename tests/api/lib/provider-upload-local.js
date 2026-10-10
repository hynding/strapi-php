'use strict';

/**
 * `@strapi/provider-upload-local` as upstream suites resolve it: the provider itself runs in the
 * worker. A suite that `jest.mock()`s this module (core/upload upload-signing) gets its mock loaded
 * by createStrapiInstance (lib/strapi.js) instead; this stand-in is what it sees otherwise.
 */
module.exports = {
  __apiTestsStandIn: true,
  init() {
    return {};
  },
};
