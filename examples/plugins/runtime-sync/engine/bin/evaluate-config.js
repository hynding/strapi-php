#!/usr/bin/env node
'use strict';

/**
 * Evaluates a Node config file (`config/*.js|ts`) into the IR (engine/ir.js) and prints it as
 * JSON: `node evaluate-config.js <projectRoot> <relativePath>`. The PHP CLI runs it to read
 * JavaScript configs; the Node CLI requires it as a module.
 *
 * The file is loaded for real (it may `require` things), but `env` is a recorder: every
 * `env('KEY', default)` / `env.int(...)` returns a marker string that is turned back into an
 * `$env` leaf afterwards, so the generated file reads the environment itself and no secret is
 * copied. A marker that ends up inside a longer string (`'a' + env('X')`,
 * `path.join(__dirname, env('X'))`) becomes a `$concat` of its parts. A reference the file
 * consumed in some other way (compared, used as a key, `&&`-ed) leaves no trace in the output;
 * that is detected by counting and reported as a warning, since the evaluation then followed
 * whatever branch a string takes.
 *
 * TypeScript files need `node --experimental-strip-types` (Node 22.6+; on by default from 23.6).
 */
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const MARK = '\u0000';
const ENV_TYPES = ['int', 'float', 'bool', 'json', 'array', 'date', 'oneOf'];

function createRecorder() {
  const refs = [];
  const record = (type, key, args, expected) => {
    const leaf = { $env: type, key: String(key) };
    if (args.length > 0 && args[0] !== undefined) leaf.default = args[0];
    if (expected !== undefined) leaf.expected = expected;
    return `${MARK}${refs.push(leaf) - 1}${MARK}`;
  };

  const env = (key, ...args) => record('string', key, args);
  for (const type of ENV_TYPES) {
    if (type === 'oneOf') continue;
    env[type] = (key, ...args) => record(type, key, args);
  }
  env.oneOf = (key, expected, ...args) => record('oneOf', key, args, Array.isArray(expected) ? expected.map(String) : undefined);

  return { env, refs };
}

/** Marker strings become `$env` / `$concat` leaves; the project root becomes `$root`. */
function restore(value, refs, root, seen) {
  if (typeof value === 'string') {
    const prefix = root + path.sep;
    if (!value.includes(MARK) && !value.startsWith(prefix) && value !== root) return value;
    if (value === root) return { $root: true };

    const parts = [];
    let text = value;
    if (text.startsWith(prefix)) {
      parts.push({ $root: true });
      text = '/' + text.slice(prefix.length).split(path.sep).join('/');
    }
    for (const piece of text.split(MARK)) {
      if (piece === '') continue;
      const ref = /^\d+$/.test(piece) ? refs[Number(piece)] : undefined;
      if (ref) {
        seen.add(Number(piece));
        parts.push(restore(ref, refs, root, seen));
      } else if (typeof parts[parts.length - 1] === 'string') {
        parts[parts.length - 1] += piece;
      } else {
        parts.push(piece);
      }
    }
    return parts.length === 1 && typeof parts[0] === 'object' && parts[0].$env ? parts[0] : { $concat: parts };
  }
  if (Array.isArray(value)) return value.map((item) => restore(item, refs, root, seen));
  if (value && typeof value === 'object') {
    if ('$env' in value) {
      const out = { ...value };
      if ('default' in out) out.default = restore(out.default, refs, root, seen);
      return out;
    }
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, restore(v, refs, root, seen)]));
  }
  return value;
}

/** How many `env(` / `env.<type>(` calls the source makes, to notice references that never reached the output. */
function countEnvCalls(source) {
  const stripped = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:\\])\/\/.*$/gm, '$1');
  const matches = stripped.match(new RegExp(`\\benv(?:\\.(?:${ENV_TYPES.join('|')}))?\\s*\\(`, 'g'));
  return matches ? matches.length : 0;
}

async function evaluate(root, relPath) {
  root = path.resolve(root);
  const file = path.resolve(root, relPath);
  const { env, refs } = createRecorder();

  let mod = await import(pathToFileURL(file).href);
  mod = mod && mod.default !== undefined ? mod.default : mod;
  if (mod && mod.__esModule && mod.default !== undefined) mod = mod.default;

  const form = typeof mod === 'function' ? 'function' : 'value';
  const raw = form === 'function' ? await mod({ env }) : mod;

  // JSON.stringify drops `undefined` members, as Strapi's config consumers never see them
  const seen = new Set();
  const value = restore(JSON.parse(JSON.stringify(raw === undefined ? null : raw)), refs, root, seen);

  const warnings = [];
  const calls = countEnvCalls(fs.readFileSync(file, 'utf8'));
  if (refs.length > seen.size || calls > refs.length) {
    const lost = Math.max(refs.length - seen.size, calls - refs.length);
    warnings.push(`${lost} env reference(s) did not reach the output: the file uses them in expressions (a comparison, a lookup, a condition); review the generated values`);
  }

  return { source: relPath.split(path.sep).join('/'), form, value, warnings };
}

module.exports = { evaluate, MARK, countEnvCalls };

if (require.main === module) {
  const [root, relPath] = process.argv.slice(2);
  if (!root || !relPath) {
    console.error('usage: evaluate-config.js <projectRoot> <relativePath>');
    process.exit(2);
  }
  evaluate(root, relPath).then(
    (doc) => process.stdout.write(JSON.stringify(doc)),
    (error) => {
      console.error(error && error.message ? error.message : String(error));
      process.exit(1);
    }
  );
}
