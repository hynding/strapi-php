'use strict';

/**
 * Mirrors engine/migrations.php: the `strapi_migrations` log names one migration per runtime
 * (`2024.01.01T00.00.00.add.js` on Node, `….php` on strapi-php). On a shared database the twin
 * that already ran must not run again, so the server half logs the twin's name before the
 * runtime's own migration runner looks (`twinsToLog`), and `pending` shows what will still run.
 * `.sql` migrations are one file for both runtimes.
 */
const { compareStrings } = require('./files');

const RUNTIME_EXTENSIONS = ['php', 'js', 'ts', 'mjs', 'cjs'];

const extensionOf = (name) => (name.includes('.') ? name.slice(name.lastIndexOf('.') + 1) : '');

/** `name.js` → `name.`; null for `.sql` and anything that is not a runtime file */
function stem(name) {
  const ext = extensionOf(name);
  if (!RUNTIME_EXTENSIONS.includes(ext)) return null;
  return name.slice(0, -ext.length);
}

/** The names to add to the log: for every executed migration, the twins present in the migrations directory that are not logged themselves. */
function twinsToLog(executed, fileNames) {
  const logged = new Set(executed);
  const out = [];
  for (const name of executed) {
    const s = stem(name);
    if (s === null) continue;
    for (const file of fileNames) {
      if (file !== name && stem(file) === s && !logged.has(file) && !out.includes(file)) out.push(file);
    }
  }
  return out.sort(compareStrings);
}

/** The migration files that would run: not logged under any runtime's spelling. */
function pending(executed, fileNames) {
  const logged = new Set(executed);
  const loggedStems = new Set(executed.map(stem).filter((s) => s !== null));
  return fileNames
    .filter((file) => {
      if (logged.has(file)) return false;
      const s = stem(file);
      return !(s !== null && loggedStems.has(s));
    })
    .sort(compareStrings);
}

/** The migration files a runtime discovers: `.sql` plus its own extensions, sorted, no dot files. */
function discoverable(fileNames, runtime) {
  const extensions = runtime === 'php' ? ['php', 'sql'] : ['js', 'sql'];
  return fileNames.filter((f) => !f.startsWith('.') && extensions.includes(extensionOf(f))).sort(compareStrings);
}

module.exports = { RUNTIME_EXTENSIONS, stem, twinsToLog, pending, discoverable };
