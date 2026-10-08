'use strict';

/**
 * Runs test files in path order. Jest's default orders by previous duration and failures, and
 * upstream's files share one database and don't all clean up (a role left behind, the super
 * admin deleted), so results changed from run to run. A fixed order makes them reproducible.
 */
const Sequencer = require('@jest/test-sequencer').default;

class PathSequencer extends Sequencer {
  sort(tests) {
    return [...tests].sort((a, b) => (a.path < b.path ? -1 : a.path > b.path ? 1 : 0));
  }
}

module.exports = PathSequencer;
