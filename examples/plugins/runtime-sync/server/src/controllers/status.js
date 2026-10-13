'use strict';

/** Mirrors controllers/status.php. */
module.exports = ({ strapi }) => ({
  /**
   * GET /runtime-sync/status (admin): this runtime, the database preflight of this boot, the
   * boots recorded by both runtimes, whether they agree, and the project's file pairs.
   */
  async find() {
    return { data: await strapi.plugin('runtime-sync').service('status').report() };
  },
});
