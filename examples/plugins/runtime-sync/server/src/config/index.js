'use strict';

/** Mirrors config/index.php: defaults from default.json (read by both runtimes), same validation. */
const defaults = require('./default.json');

module.exports = {
  default: defaults,
  validator(config) {
    for (const key of ['reconcileMigrations', 'recordBoots']) {
      if (typeof config[key] !== 'boolean') throw new Error(`${key} must be a boolean`);
    }
  },
};
