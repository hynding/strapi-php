'use strict';

/**
 * Mirrors engine/generate.php: plans and writes the files a project lacks for a runtime. A plan
 * is a list of actions the CLI shows (`--dry-run`) or applies; nothing existing is overwritten
 * unless `force`, and manifests (package.json, .gitignore) are merged, never replaced.
 */
const fs = require('node:fs');
const path = require('node:path');
const emit = require('./emit');
const evaluateConfig = require('./evaluate-config');
const files = require('./files');
const ir = require('./ir');
const project = require('./project');
const scan = require('./scan');
const source = require('./source');
const spec = require('./spec');
const templates = require('./templates');

async function plan(root, options = {}) {
  root = files.realRoot(root);
  const inspection = project.inspect(root);
  const scanned = scan.run(root);
  const targetList = targets(inspection, options.to ?? null);
  const language = options.language ?? inspection.node.language;
  const force = options.force ?? false;

  const actions = [];
  for (const target of targetList) {
    const ext = target === 'php' ? 'php' : language;
    for (const pair of scanned.paired) {
      if (pair.state !== `missing-${target}`) continue;
      const src = target === 'php' ? pair.node : pair.php;
      const p = `${pair.stem}.${ext}`;
      const facet = spec.pairedFacet(pair.stem) ?? { id: pair.facet, glob: '', generator: 'stub' };
      actions.push(await pairedAction(root, p, target, ext, src, facet, force));
    }
    actions.push(...manifestActions(root, inspection, target, force));
  }
  return { targets: targetList, actions };
}

function targets(inspection, to) {
  if (to === 'node' || to === 'php') return [to];
  if (to === 'both' || inspection.node.present === inspection.php.present) return ['node', 'php'];
  return inspection.node.present ? ['php'] : ['node'];
}

async function pairedAction(root, p, target, lang, src, facet, force) {
  const action = { path: p, target, generator: facet.generator ?? 'stub', source: src, action: 'write', content: null, review: false, notes: [] };
  if (fs.existsSync(path.join(root, p)) && !force) return { ...action, action: 'skip', notes: ['exists'] };

  const code = files.read(path.join(root, src));
  const sourceLang = files.extension(src) === 'php' ? 'php' : files.extension(src) === 'ts' ? 'ts' : 'js';

  try {
    switch (facet.generator ?? 'stub') {
      case 'config': {
        const template = templates.counterpart(src, code, lang);
        if (template !== null) {
          action.content = template;
          action.notes.push("unchanged template: the other runtime's template");
          break;
        }
        const doc = await evaluateConfig.evaluate(root, src);
        const emitted = emit.config(doc, lang, p);
        action.content = emitted.content;
        action.review = emitted.review;
        action.notes.push(...doc.warnings);
        break;
      }
      case 'app-entry': {
        const content = templates.read(target, `src/index.${lang}`);
        if (content === null) throw new Error(`no template for src/index.${lang}`);
        action.content = content;
        action.review = source.normalize(code, sourceLang) !== source.normalize(templates.read(target === 'php' ? 'node' : 'php', src) ?? '', sourceLang);
        if (action.review) {
          action.notes.push(`${src} has custom register/bootstrap code: port it by hand`);
          action.content += `\n/* Original (${src}):\n${code.replace(/\s+$/, '').replace(/\*\//g, '* /')}\n*/\n`;
        }
        break;
      }
      case 'core-factory': {
        const factory = source.detectCoreFactory(code, sourceLang);
        let data = null;
        if (factory !== null && factory.plain) {
          action.content = emit.coreFactory(factory.factory, factory.uid, lang);
        } else if (factory === null && (data = await asData(root, src)) !== null) {
          // a routes file is data (`{ routes: [...] }`): translated like a config file
          action.content = emit.config(data, lang, p).content;
          action.notes.push('data file: translated');
        } else {
          const shape = factory === null ? 'module' : factory.kind;
          action.content = emit.stub(shape, lang, src, code, factory ? factory.uid : null);
          action.review = true;
          action.notes.push(factory === null ? 'no core factory found: stub' : `customised ${factory.factory}: stub`);
        }
        break;
      }
      default:
        action.content = emit.stub(facet.shape ?? 'module', lang, src, code);
        action.review = true;
        action.notes.push('stub: port by hand');
    }
  } catch (e) {
    return { ...action, action: 'error', notes: [e.message] };
  }
  return action;
}

/** A file that evaluates to plain data (no env reads, nothing untranslatable), else null. */
async function asData(root, src) {
  let doc;
  try {
    doc = await evaluateConfig.evaluate(root, src);
  } catch {
    return null;
  }
  if (doc.form !== 'value' || doc.warnings.length > 0 || ir.countTodos(doc.value) > 0 || ir.usesEnv(doc.value)) return null;
  return doc;
}

function manifestActions(root, inspection, target, force) {
  const manifests = spec.manifests();
  const runtimeSpec = manifests[target];
  const version = project.bestVersion([inspection.node.upstreamVersion, inspection.php.upstreamVersion]);
  const actions = [];
  const make = (p, generator, action, content, notes = []) => ({ path: p, target, generator, source: null, action, content, review: false, notes });

  if (target === 'php') {
    if (!fs.existsSync(path.join(root, 'composer.json')) || force) {
      actions.push(
        version === null
          ? make('composer.json', 'manifest', 'error', null, ['cannot tell the Strapi version: package.json has no @strapi/strapi dependency'])
          : make('composer.json', 'manifest', 'write', composerJson(inspection, version))
      );
    }
    for (const file of ['bin/strapi', 'public/index.php']) {
      if (!fs.existsSync(path.join(root, file)) || force) actions.push(make(file, 'template', 'write', templates.read('php', file)));
    }
  }

  // package.json: dependencies and scripts, merged
  const packageJson = inspection.packageJson && typeof inspection.packageJson === 'object' ? inspection.packageJson : {};
  const added = [];
  const merged = mergePackageJson(packageJson, runtimeSpec, target, version, String(inspection.env.databaseClient ?? 'sqlite'), String(inspection.name), added);
  if (version === null) added.push('Strapi version unknown: @strapi/* dependencies not added');
  if (inspection.packageJson === null) actions.push(make('package.json', 'manifest', 'write', files.encodeJson(merged), added));
  else if (added.length > 0 && JSON.stringify(merged) !== JSON.stringify(packageJson)) actions.push(make('package.json', 'manifest', 'merge', files.encodeJson(merged, indentOf(path.join(root, 'package.json'))), added));
  else actions.push(make('package.json', 'manifest', 'skip', null, ['up to date', ...added]));

  // .gitignore: lines appended
  const gitignorePath = path.join(root, '.gitignore');
  const gitignore = fs.existsSync(gitignorePath) ? files.read(gitignorePath) : '';
  const lines = gitignore.split(/\r?\n/).map((l) => l.trim());
  const missing = runtimeSpec.gitignore.filter((line) => !lines.includes(line) && !lines.includes(`/${line}`));
  if (missing.length > 0) {
    const content = `${gitignore.replace(/\s+$/, '')}${gitignore === '' ? '' : '\n'}${missing.join('\n')}\n`;
    actions.push(make('.gitignore', 'manifest', gitignore === '' ? 'write' : 'merge', content, missing));
  }

  return actions;
}

function mergePackageJson(packageJson, runtimeSpec, target, version, databaseClient, name, added) {
  let out = { ...packageJson };
  if (Object.keys(packageJson).length === 0) out = { name: kebab(name), version: '0.1.0', private: true };
  const deps = { ...(out.dependencies && typeof out.dependencies === 'object' ? out.dependencies : {}) };
  const all = project.dependencies(out);

  const wanted = { ...runtimeSpec.dependencies };
  if (target === 'node') {
    const driver = runtimeSpec.databaseDrivers[databaseClient] ?? null;
    if (driver !== null) wanted[driver] = runtimeSpec.databaseDriverVersions[driver] ?? '*';
  }
  for (const [dep, spec_] of Object.entries(wanted)) {
    if (dep in all) continue;
    let constraint = spec_;
    if (constraint === '<version>') {
      if (version === null) continue;
      constraint = version;
    }
    deps[dep] = constraint;
    added.push(`dependency ${dep}@${constraint}`);
  }
  if (Object.keys(deps).length > 0) {
    out.dependencies = Object.fromEntries(Object.keys(deps).sort(files.compareStrings).map((k) => [k, deps[k]]));
  }

  const scripts = { ...(out.scripts && typeof out.scripts === 'object' ? out.scripts : {}) };
  for (const [key, command] of Object.entries(runtimeSpec.scripts)) {
    if (!(key in scripts)) {
      scripts[key] = command;
      added.push(`script ${key}`);
    } else if (scripts[key] !== command && !(`${key}:${target}` in scripts)) {
      scripts[`${key}:${target}`] = command;
      added.push(`script ${key}:${target}`);
    }
  }
  out.scripts = scripts;
  return out;
}

/** The composer.json create-strapi-app would write for this project. */
function composerJson(inspection, version) {
  const client = String(inspection.env.databaseClient ?? 'sqlite');
  const ext = { sqlite: 'ext-pdo_sqlite', postgres: 'ext-pdo_pgsql', mysql: 'ext-pdo_mysql', mariadb: 'ext-pdo_mysql' }[client] ?? null;
  const preRelease = /-(alpha|beta|rc)/i.test(version);
  const m = /^(\d+)\.(\d+)/.exec(version);
  const constraint = preRelease ? version : m ? `^${m[1]}.${m[2]}` : version;

  const require_ = { php: '>=8.3' };
  if (ext !== null) require_[ext] = '*';
  require_['hynding/strapi-php'] = constraint;

  const composer = {
    description: 'A Strapi application',
    type: 'project',
    require: require_,
    scripts: (files.readJson(path.join(templates.dir(), 'php', 'composer.json')) ?? {}).scripts ?? {},
    config: { 'sort-packages': true, 'process-timeout': 0, 'allow-plugins': false },
  };
  const uuid = inspection.packageJson && inspection.packageJson.strapi ? inspection.packageJson.strapi.uuid : undefined;
  if (typeof uuid === 'string') composer.extra = { strapi: { uuid } };
  if (preRelease) {
    composer['minimum-stability'] = 'beta';
    composer['prefer-stable'] = true;
  }
  return files.encodeJson(composer, 4);
}

const kebab = (name) =>
  name
    .replace(/^@?[^/]+\//, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '') || 'strapi-app';

/** The indentation a JSON file uses (2 or 4 spaces). */
function indentOf(file) {
  const content = fs.existsSync(file) ? files.read(file) : '';
  const m = /^\n?\{\n( +)/.exec(content);
  return m ? m[1].length : 2;
}

function apply(root, planned, dryRun = false) {
  const results = [];
  for (const action of planned.actions) {
    const result = { path: action.path, action: action.action, review: action.review, notes: action.notes };
    if ((action.action === 'write' || action.action === 'merge') && action.content !== null) {
      if (dryRun) {
        result.action = `would-${action.action}`;
      } else {
        files.write(path.join(root, action.path), action.content);
        if (action.path === 'bin/strapi') fs.chmodSync(path.join(root, action.path), 0o755);
        result.action = action.action === 'write' ? 'written' : 'merged';
      }
    }
    results.push(result);
  }
  return results;
}

module.exports = { plan, targets, mergePackageJson, composerJson, kebab, indentOf, apply };
