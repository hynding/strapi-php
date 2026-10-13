'use strict';

/**
 * Mirrors engine/status.php: the project report (`strapi-runtime-sync status`, and the `project`
 * part of the admin status route). Manifests and files only; the database part comes from the
 * server half, which has a booted Strapi.
 */
const fs = require('node:fs');
const path = require('node:path');
const files = require('./files');
const project = require('./project');
const scan = require('./scan');
const spec = require('./spec');

const problem = (level, code, message) => ({ level, code, message });

function report(root) {
  root = files.realRoot(root);
  const inspection = project.inspect(root);
  const scanned = scan.run(root);
  const manifests = spec.manifests();
  const problems = [];
  const node = inspection.node;
  const php = inspection.php;

  if (!node.present && !php.present) {
    problems.push(problem('error', 'no-runtime', 'Neither runtime is set up here: no config/*.js|ts or src/index.js|ts for Node, no config/*.php or src/index.php for strapi-php.'));
  }

  let lockstep = null;
  if (node.upstreamVersion !== null && php.upstreamVersion !== null) {
    lockstep = project.versionsAgree(node.upstreamVersion, php.upstreamVersion);
    if (!lockstep) {
      problems.push(problem('error', 'versions-differ', `package.json runs Strapi ${node.upstreamVersion} but composer.json runs strapi-php ${php.version} (Strapi ${php.upstreamVersion}): the two must mirror the same release to share a database (VERSIONING.md).`));
    }
  }

  const counts = { paired: 0, missingNode: 0, missingPhp: 0, shared: scanned.shared.count };
  for (const pair of scanned.paired) counts[pair.state === 'paired' ? 'paired' : pair.state === 'missing-node' ? 'missingNode' : 'missingPhp']++;
  if (counts.missingNode > 0) problems.push(problem(node.present ? 'warning' : 'info', 'node-files-missing', `${counts.missingNode} file(s) have no Node twin: \`strapi-runtime-sync generate --to node\`.`));
  if (counts.missingPhp > 0) problems.push(problem(php.present ? 'warning' : 'info', 'php-files-missing', `${counts.missingPhp} file(s) have no strapi-php twin: \`strapi-runtime-sync generate --to php\`.`));

  // manifests
  const deps = project.dependencies(inspection.packageJson);
  const nodeMissingDeps = Object.keys(manifests.node.dependencies).filter((dep) => !(dep in deps));
  const driver = manifests.node.databaseDrivers[inspection.env.databaseClient] ?? null;
  if (driver !== null && !(driver in deps)) nodeMissingDeps.push(driver);
  const phpMissingFiles = manifests.php.files.filter((f) => !fs.existsSync(path.join(root, f)));
  const phpMissingDeps = Object.keys(manifests.php.dependencies).filter((dep) => !(dep in deps));
  if (node.present && nodeMissingDeps.length > 0) problems.push(problem('warning', 'node-manifest', `package.json lacks ${nodeMissingDeps.join(', ')} (\`generate --to node\` adds them).`));
  if (php.present && (phpMissingFiles.length > 0 || phpMissingDeps.length > 0)) problems.push(problem('warning', 'php-manifest', `strapi-php needs ${[...phpMissingFiles, ...phpMissingDeps].join(', ')} (\`generate --to php\` adds them).`));

  // environment
  let missingKeys = [];
  if (!inspection.env.present) {
    problems.push(problem('warning', 'env-missing', 'No .env: both runtimes read the same .env (copy .env.example and set the secrets).'));
  } else {
    missingKeys = manifests.env.filter((key) => !inspection.env.keys.includes(key));
    if (missingKeys.length > 0) problems.push(problem('warning', 'env-keys-missing', `.env lacks ${missingKeys.join(', ')}: tokens and sessions issued by one runtime are only valid on the other with the same secrets.`));
  }

  // plugins
  const pluginSets = plugins(root, inspection);
  const ignored = manifests.pluginsNotCompared ?? [];
  const nodeOnly = pluginSets.node.filter((p) => !pluginSets.php.includes(p) && !ignored.includes(p));
  const phpOnly = pluginSets.php.filter((p) => !pluginSets.node.includes(p) && !ignored.includes(p));
  if (node.present && php.present && (nodeOnly.length > 0 || phpOnly.length > 0)) {
    const parts = [];
    if (nodeOnly.length > 0) parts.push(`Node only: ${nodeOnly.join(', ')}`);
    if (phpOnly.length > 0) parts.push(`strapi-php only: ${phpOnly.join(', ')}`);
    problems.push(problem('warning', 'plugins-differ', `Plugins differ (${parts.join('; ')}). A plugin with content types must be enabled on both runtimes, or each boot alters the shared database: install it on the other side or disable it in config/plugins, then \`db:check\`.`));
  }

  if (scanned.unsupported.length > 0) problems.push(problem('warning', 'unsupported', `Not generated, port by hand: ${scanned.unsupported.join(', ')} (a dual plugin looks like examples/plugins/announcements).`));
  if (!inspection.adminBuild.present) problems.push(problem('info', 'admin-build', 'No admin build: run `strapi build` on either runtime; both serve build/ (dist/build for a TypeScript project).'));

  return {
    root,
    name: inspection.name,
    runtimes: { node, php },
    versions: { lockstep, upstream: node.upstreamVersion ?? php.upstreamVersion },
    counts,
    files: scanned.paired,
    shared: scanned.shared,
    unsupported: scanned.unsupported,
    manifests: {
      node: { missingDependencies: nodeMissingDeps, missingFiles: manifests.node.files.filter((f) => !fs.existsSync(path.join(root, f))) },
      php: { missingDependencies: phpMissingDeps, missingFiles: phpMissingFiles },
    },
    env: { present: inspection.env.present, missingKeys, databaseClient: inspection.env.databaseClient },
    adminBuild: inspection.adminBuild,
    plugins: { ...pluginSets, nodeOnly, phpOnly },
    problems,
  };
}

/** The plugins each runtime would enable, from the manifests (best effort: the boot markers the server half records are the truth). */
function plugins(root, inspection) {
  const node = [];
  for (const dep of Object.keys(project.dependencies(inspection.packageJson))) {
    if (dep.startsWith('@strapi/plugin-')) {
      node.push(dep.slice('@strapi/plugin-'.length));
      continue;
    }
    const pkg = files.readJson(path.join(root, 'node_modules', dep, 'package.json'));
    if (pkg && pkg.strapi && pkg.strapi.kind === 'plugin') node.push(String(pkg.strapi.name ?? dep));
  }

  const php = [];
  const require_ = inspection.composerJson && inspection.composerJson.require && typeof inspection.composerJson.require === 'object' ? inspection.composerJson.require : {};
  for (const dep of Object.keys(require_)) if (dep.startsWith('strapi/plugin-')) php.push(dep.slice('strapi/plugin-'.length));
  if ('hynding/strapi-php' in require_) php.push(...spec.manifests().php.bundledPlugins);
  const installed = files.readJson(path.join(root, 'vendor/composer/installed.json'));
  const list = installed && Array.isArray(installed.packages) ? installed.packages : Array.isArray(installed) ? installed : [];
  for (const pkg of list) {
    if (pkg && pkg.extra && pkg.extra.strapi && pkg.extra.strapi.kind === 'plugin') php.push(String(pkg.extra.strapi.name ?? path.basename(String(pkg.name))));
  }

  return { node: [...new Set(node)].sort(files.compareStrings), php: [...new Set(php)].sort(files.compareStrings) };
}

const hasErrors = (rep) => (rep.problems ?? []).some((p) => p.level === 'error');

module.exports = { report, plugins, problem, hasErrors };
