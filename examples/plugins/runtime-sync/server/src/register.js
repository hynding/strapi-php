'use strict';

/**
 * Mirrors register.php. Hooks the two moments of a boot that matter to a shared database:
 *
 * - `strapi::content-types.beforeSync` runs once the database is initialised and before the
 *   schema sync and the migrations. The preflight service compares this runtime's schema with
 *   the stored one, lists the migrations about to run, logs the twins the other runtime already
 *   ran, and — when `STRAPI_RUNTIME_SYNC_PREFLIGHT=1` (`strapi-runtime-sync db:check`) — aborts
 *   the boot with the report before anything is written.
 * - `strapi::content-types.afterSync` records this boot (runtime, version, schema hash, plugins)
 *   in the core store, so the other runtime and the admin page can see both sides agree.
 */
module.exports = ({ strapi }) => {
  const preflight = () => strapi.plugin('runtime-sync').service('preflight');

  strapi.hook('strapi::content-types.beforeSync').register(() => preflight().beforeSync());
  strapi.hook('strapi::content-types.afterSync').register(() => preflight().afterSync());
};
