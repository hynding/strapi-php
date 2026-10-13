'use strict';

/**
 * Mirrors engine/templates.php: the project files create-strapi-app writes, for both runtimes
 * (spec/templates/node = upstream's vanilla-js and vanilla templates, spec/templates/php =
 * strapi-php's). A config file a project never touched is recognised by its normalised source and
 * answered with the other runtime's template, instead of being translated.
 */
const fs = require('node:fs');
const path = require('node:path');
const files = require('./files');
const source = require('./source');
const spec = require('./spec');

const dir = () => path.join(__dirname, '..', 'spec', 'templates');

function read(runtime, relPath) {
  const file = path.join(dir(), runtime, relPath);
  return fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : null;
}

/** The other runtime's template for a file that is itself an unchanged template, else null. `targetExt` is `php`, `js` or `ts`. */
function counterpart(relPath, code, targetExt) {
  const stem = files.stem(relPath);
  const ext = files.extension(relPath);
  const sourceRuntime = spec.runtimeOfExtension(ext);
  if (sourceRuntime === null) return null;
  const lang = ext === 'php' ? 'php' : ext === 'ts' ? 'ts' : 'js';

  const template = read(sourceRuntime, `${stem}.${ext}`);
  if (template === null || source.normalize(template, lang) !== source.normalize(code, lang)) return null;

  return read(targetExt === 'php' ? 'php' : 'node', `${stem}.${targetExt}`);
}

module.exports = { dir, read, counterpart };
