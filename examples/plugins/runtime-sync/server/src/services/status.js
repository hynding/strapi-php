'use strict';

/**
 * Mirrors services/status.php: the admin status document. The runtime answering, what this boot
 * found in the database (preflight), both runtimes' last boots, whether they agree, and the
 * project's file pairs (engine status).
 */
const projectStatus = require('../../../engine/status');

/** The boot that stored the current schema hash, if a recorded one did. */
function storedBy(storedHash, boots) {
  let match = null;
  for (const boot of Object.values(boots)) {
    if (storedHash !== null && boot.schemaHash === storedHash && (match === null || String(boot.time) > String(match.time))) match = boot;
  }
  return match === null ? null : { runtime: String(match.runtime), version: String(match.version), time: String(match.time) };
}

/** Do the two runtimes' last boots agree? null until both have booted. */
function agreement(boots) {
  const node = boots.node ?? null;
  const php = boots.php ?? null;
  if (!node || !php) return { sameVersion: null, sameSchema: null, samePlugins: null, pluginsNodeOnly: [], pluginsPhpOnly: [] };
  const nodePlugins = (node.plugins ?? []).map(String);
  const phpPlugins = (php.plugins ?? []).map(String);
  return {
    sameVersion: node.version === php.version,
    sameSchema: node.schemaHash === php.schemaHash,
    samePlugins: JSON.stringify(nodePlugins) === JSON.stringify(phpPlugins),
    pluginsNodeOnly: nodePlugins.filter((p) => !phpPlugins.includes(p)),
    pluginsPhpOnly: phpPlugins.filter((p) => !nodePlugins.includes(p)),
  };
}

module.exports = ({ strapi }) => ({
  async report() {
    const preflight = strapi.plugin('runtime-sync').service('preflight');
    const boots = await preflight.boots();
    let database = preflight.database();
    if (database) database = { ...database, storedBy: storedBy(database.storedHash, boots) };
    return {
      runtime: preflight.runtime(),
      database,
      boots,
      agreement: agreement(boots),
      project: projectStatus.report(strapi.dirs.app.root),
    };
  },
});

module.exports.storedBy = storedBy;
module.exports.agreement = agreement;
