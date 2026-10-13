'use strict';

/**
 * Mirrors engine/evaluate-config.php: turns a config file into the IR (see ir.js). Each
 * language is read by its own runtime: a `.js`/`.ts` file is evaluated here with a recording
 * `env` (engine/bin/evaluate-config.js), a `.php` file is parsed by PHP
 * (engine/bin/evaluate-config.php, nikic/php-parser: `$env(...)` calls stay references, no
 * secret lands in the generated file). Each CLI calls the other interpreter for the other language.
 */
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const files = require('./files');
const ir = require('./ir');
const spec = require('./spec');

async function evaluate(root, relPath) {
  const ext = files.extension(relPath);
  if (ext === 'php') return evaluateWithPhp(root, relPath);
  if (spec.runtimeOfExtension(ext) === 'node') return evaluateNode(root, relPath);
  throw new Error(`${relPath} is not a config file of either runtime`);
}

async function evaluateNode(root, relPath) {
  const ext = files.extension(relPath);
  if (ext === 'ts' || ext === 'mts') {
    // a separate process so --experimental-strip-types applies
    const result = spawnSync(process.execPath, ['--experimental-strip-types', '--no-warnings', path.join(__dirname, 'bin', 'evaluate-config.js'), files.realRoot(root), relPath], { cwd: files.realRoot(root), encoding: 'utf8', timeout: 60000 });
    if (result.status !== 0) throw new Error(`Node could not evaluate ${relPath}: ${(result.stderr || result.stdout || '').trim()}`);
    return ir.decode(result.stdout);
  }
  return require('./bin/evaluate-config.js').evaluate(files.realRoot(root), relPath);
}

function evaluateWithPhp(root, relPath) {
  const php = process.env.RUNTIME_SYNC_PHP || 'php';
  const result = spawnSync(php, [path.join(__dirname, 'bin', 'evaluate-config.php'), files.realRoot(root), relPath], { cwd: files.realRoot(root), encoding: 'utf8', timeout: 60000 });
  if (result.error || result.status !== 0) {
    const message = result.error ? result.error.message : (result.stderr || result.stdout || '').trim();
    throw new Error(`PHP could not evaluate ${relPath}: ${message}`);
  }
  return ir.decode(result.stdout);
}

module.exports = { evaluate, evaluateNode, evaluateWithPhp };
