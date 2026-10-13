'use strict';

/**
 * Mirrors engine/project.php: what the manifests say about a project. Which runtimes it is set
 * up for, at which Strapi version, its `.env`, its admin build. Nothing here boots Strapi.
 */
const fs = require('node:fs');
const path = require('node:path');
const files = require('./files');
const spec = require('./spec');

function inspect(root) {
  const packageJson = files.readJson(path.join(root, 'package.json'));
  const composerJson = files.readJson(path.join(root, 'composer.json'));

  const nodeDeps = dependencies(packageJson);
  const nodeStrapi = nodeDeps['@strapi/strapi'] ?? null;
  const nodeVersion = typeof nodeStrapi === 'string' ? cleanVersion(nodeStrapi) : null;

  const phpRequire = composerJson && composerJson.require && typeof composerJson.require === 'object' ? composerJson.require : {};
  const phpConstraint = phpRequire['hynding/strapi-php'] ?? phpRequire['strapi/strapi'] ?? phpRequire['strapi/core'] ?? null;
  const phpInstalled = installedComposerVersion(root);
  const phpVersion = phpInstalled ?? (typeof phpConstraint === 'string' ? cleanVersion(phpConstraint) : null);

  const env = readEnv(path.join(root, '.env'));

  let adminBuildDir = null;
  for (const candidate of ['build', 'dist/build']) {
    if (fs.existsSync(path.join(root, candidate, 'index.html'))) {
      adminBuildDir = candidate;
      break;
    }
  }

  const name = typeof packageJson?.name === 'string' ? packageJson.name : typeof composerJson?.name === 'string' ? composerJson.name : path.basename(root);

  return {
    root,
    name,
    node: {
      present: nodeStrapi !== null && hasRuntimeFiles(root, 'node'),
      version: nodeVersion,
      upstreamVersion: nodeVersion === null ? null : upstreamVersion(nodeVersion),
      language: fs.existsSync(path.join(root, 'tsconfig.json')) || fs.existsSync(path.join(root, 'src/index.ts')) ? 'ts' : 'js',
      installed: fs.existsSync(path.join(root, 'node_modules/@strapi/strapi')),
    },
    php: {
      present: phpConstraint !== null && hasRuntimeFiles(root, 'php'),
      version: phpVersion,
      upstreamVersion: phpVersion === null ? null : upstreamVersion(phpVersion),
      installed: phpInstalled !== null,
    },
    packageJson,
    composerJson,
    env,
    adminBuild: { present: adminBuildDir !== null, dir: adminBuildDir },
  };
}

/** A runtime is "present" when the project has that runtime's own files: config or the app entry. */
function hasRuntimeFiles(root, runtime) {
  for (const ext of spec.extensions()[runtime]) {
    if (fs.existsSync(path.join(root, `src/index.${ext}`))) return true;
    const configDir = path.join(root, 'config');
    if (fs.existsSync(configDir) && fs.readdirSync(configDir).some((f) => f.endsWith(`.${ext}`) && fs.statSync(path.join(configDir, f)).isFile())) return true;
  }
  return false;
}

/** dependencies + devDependencies of a package.json. */
const dependencies = (packageJson) => ({
  ...(packageJson && typeof packageJson.devDependencies === 'object' ? packageJson.devDependencies : {}),
  ...(packageJson && typeof packageJson.dependencies === 'object' ? packageJson.dependencies : {}),
});

/** `^5.56.0`, `~5.56`, `=v5.56.0`, `5.56.0-beta.1` → without the range operator. */
const cleanVersion = (constraint) => constraint.trim().replace(/^[\^~>=v ]+/, '').trim();

/** The upstream Strapi release of a version (VERSIONING.md): `5.56.0` for `5.56.0`, `5.56.0.1` and `5.56.0-beta.1`; `5.56` stays `5.56`. */
const upstreamVersion = (version) => {
  const m = /^(\d+\.\d+(?:\.\d+)?)/.exec(version);
  return m ? m[1] : version;
};

/** The most precise of several upstream versions: a full `x.y.z` over a `x.y` range floor. */
function bestVersion(versions) {
  const known = versions.filter((v) => typeof v === 'string');
  return known.find((v) => /^\d+\.\d+\.\d+/.test(v)) ?? known[0] ?? null;
}

/** Whether two upstream versions name the same release (`5.56` is the floor of `5.56.0`). */
const versionsAgree = (a, b) => a === b || a.startsWith(`${b}.`) || b.startsWith(`${a}.`);

/** The installed `hynding/strapi-php` (or `strapi/strapi`) version from vendor/composer/installed.json. */
function installedComposerVersion(root) {
  const installed = files.readJson(path.join(root, 'vendor/composer/installed.json'));
  const list = installed && Array.isArray(installed.packages) ? installed.packages : Array.isArray(installed) ? installed : [];
  for (const name of ['hynding/strapi-php', 'strapi/strapi']) {
    const found = list.find((p) => p && p.name === name && typeof p.version === 'string');
    if (found) return cleanVersion(found.version);
  }
  return null;
}

/** The keys of a `.env` file and the database client it selects. */
function readEnv(file) {
  if (!fs.existsSync(file)) return { present: false, keys: [], databaseClient: 'sqlite' };
  const keys = [];
  const values = {};
  for (const raw of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const line = raw.trim();
    if (line === '' || line.startsWith('#') || !line.includes('=')) continue;
    const index = line.indexOf('=');
    const key = line.slice(0, index).replace(/^export\s+/, '').trim();
    const value = line.slice(index + 1).trim().replace(/^["']|["']$/g, '');
    keys.push(key);
    values[key] = value;
  }
  const client = values.DATABASE_CLIENT ?? 'sqlite';
  return { present: true, keys: [...new Set(keys)], databaseClient: client === '' ? 'sqlite' : client };
}

module.exports = { inspect, hasRuntimeFiles, dependencies, cleanVersion, upstreamVersion, bestVersion, versionsAgree, installedComposerVersion, readEnv };
