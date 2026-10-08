'use strict';

/**
 * Before each test file:
 * - no worker from a previous file may survive (see lib/server.js stopAll);
 * - the app's src/ goes back to the template. Upstream test files create their content types
 *   through the CTB and delete them in afterAll; a file whose setup throws leaves its schemas
 *   behind, and one that can't boot here (e.g. a relation to a plugin not ported yet) would
 *   break every file after it;
 * - the SQLite database starts empty. Upstream runs every file against one database, but its
 *   files don't all clean up (users and roles left behind, settings left on, the super admin
 *   deleted), so a file's result depended on which files ran before it. Each file creates the
 *   data it needs, so a fresh database gives every file the state it was written for.
 */
const fs = require('fs');
const path = require('path');
const { stopAll } = require('./server');
const { apiTestsDir, appDir } = require('./paths');

beforeAll(async () => {
  await stopAll();
  const src = path.join(appDir, 'src');
  fs.rmSync(src, { recursive: true, force: true });
  fs.cpSync(path.join(apiTestsDir, 'app', 'src'), src, { recursive: true });
  for (const file of ['data.db', 'data.db-wal', 'data.db-shm']) {
    fs.rmSync(path.join(appDir, '.tmp', file), { force: true });
  }
});
