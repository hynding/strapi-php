'use strict';

/** Mirrors engine/files.php: the few file helpers the engine needs, with `/` paths relative to a root. */
const fs = require('node:fs');
const path = require('node:path');
const spec = require('./spec');

/** null when missing or not a JSON object */
function readJson(file) {
  if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return null;
  try {
    const decoded = JSON.parse(fs.readFileSync(file, 'utf8'));
    return decoded && typeof decoded === 'object' ? decoded : null;
  } catch {
    return null;
  }
}

/** JSON.stringify(value, null, indent) + newline, the way npm writes package.json. */
const encodeJson = (value, indent = 2) => `${JSON.stringify(value, null, indent)}\n`;

/** The absolute project root without a trailing slash. */
const realRoot = (root) => {
  let real = path.resolve(root);
  try {
    real = fs.realpathSync(real);
  } catch {
    // keep the resolved path
  }
  return real.replace(/[\\/]+$/, '');
};

const read = (file) => fs.readFileSync(file, 'utf8');

function write(file, content) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, content);
}

/** Every file under `root`, as `/`-separated relative paths in sorted order, skipping the directories the spec ignores. */
function walk(root) {
  const out = [];
  const visit = (dir) => {
    for (const entry of fs.readdirSync(dir).sort(compareStrings)) {
      const abs = path.join(dir, entry);
      const rel = path.relative(root, abs).split(path.sep).join('/');
      if (spec.isIgnored(rel)) continue;
      if (fs.statSync(abs).isDirectory()) visit(abs);
      else out.push(rel);
    }
  };
  visit(root);
  return out.sort(compareStrings);
}

/** PHP's SORT_STRING: byte order, not locale order. */
const compareStrings = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

const extension = (p) => (path.posix.extname(p) ? path.posix.extname(p).slice(1) : '');
const stem = (p) => (extension(p) === '' ? p : p.slice(0, -(extension(p).length + 1)));
const depth = (p) => p.split('/').length - 1;

module.exports = { readJson, encodeJson, realRoot, read, write, walk, extension, stem, depth, compareStrings };
