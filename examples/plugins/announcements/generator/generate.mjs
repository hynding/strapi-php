#!/usr/bin/env node
/**
 * Generates the types both servers share from the plugin's JSON spec, and checks that the two
 * hand-written servers stay file-for-file in step.
 *
 *   node generator/generate.mjs           write server/src/generated/* and admin/src/generated/*
 *   node generator/generate.mjs --check   exit 1 if anything generated is stale, or if parity breaks
 *
 * Inputs (the spec, read by both runtimes as-is):
 *   server/src/content-types/<name>/schema.json
 *   server/src/routes/*.json
 * Plus package.json (`strapi.name`) and composer.json (`extra.strapi.name`, `extra.strapi.namespace`).
 *
 * No dependencies: plain Node, string templates.
 */
import { readFileSync, writeFileSync, readdirSync, existsSync, mkdirSync, unlinkSync, statSync } from 'node:fs';
import { join, relative, dirname, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const check = process.argv.includes('--check');

const readJson = (path) => JSON.parse(readFileSync(join(root, path), 'utf8'));
const pkg = readJson('package.json');
const composer = readJson('composer.json');
const pluginName = pkg.strapi.name;
const phpNamespace = composer.extra.strapi.namespace;

// ---------------------------------------------------------------------------------------------
// naming helpers

const studly = (s) => s.replace(/(^|[-_\s.])(\w)/g, (_, __, c) => c.toUpperCase());
const camel = (s) => studly(s).replace(/^./, (c) => c.toLowerCase());
const constCase = (s) => s.replace(/([a-z0-9])([A-Z])/g, '$1_$2').replace(/[-.\s]/g, '_').toUpperCase();
const phpString = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

// ---------------------------------------------------------------------------------------------
// read the spec

const ctDir = 'server/src/content-types';
const contentTypes = readdirSync(join(root, ctDir))
  .filter((name) => existsSync(join(root, ctDir, name, 'schema.json')))
  .sort()
  .map((name) => {
    const source = `${ctDir}/${name}/schema.json`;
    const schema = readJson(source);
    return { name, source, schema, uid: `plugin::${pluginName}.${schema.info.singularName}`, className: studly(schema.info.singularName) };
  });

const routeFiles = readdirSync(join(root, 'server/src/routes')).filter((f) => f.endsWith('.json')).sort();
/** controller name -> sorted action names, from every route handler */
const handlers = {};
for (const file of routeFiles) {
  const router = readJson(`server/src/routes/${file}`);
  for (const route of router.routes ?? []) {
    const handler = route.handler.replace(new RegExp(`^plugin::${pluginName}\\.`), '');
    const [controller, action] = handler.split('.');
    if (!controller || !action) throw new Error(`routes/${file}: handler "${route.handler}" is not "controller.action"`);
    (handlers[controller] ??= new Set()).add(action);
  }
}
const controllers = Object.keys(handlers).sort().map((name) => ({ name, actions: [...handlers[name]].sort() }));

// ---------------------------------------------------------------------------------------------
// attribute type mapping

const SYSTEM_FIELDS = [
  ['id', { type: 'integer', required: true }],
  ['documentId', { type: 'string', required: true }],
];
const TIMESTAMPS = [
  ['createdAt', { type: 'datetime', required: true }],
  ['updatedAt', { type: 'datetime', required: true }],
  ['publishedAt', { type: 'datetime', required: false }],
];

const fieldsOf = (ct) => {
  const fields = [...SYSTEM_FIELDS, ...Object.entries(ct.schema.attributes), ...TIMESTAMPS];
  if (ct.schema.pluginOptions?.i18n?.localized) fields.push(['locale', { type: 'string', required: false }]);
  return fields;
};

const enumName = (ct, attr) => `${ct.className}${studly(attr)}`;

function tsType(ct, attrName, attr) {
  switch (attr.type) {
    case 'string': case 'text': case 'richtext': case 'email': case 'password': case 'uid':
    case 'date': case 'datetime': case 'time': case 'timestamp': case 'biginteger':
      return 'string';
    case 'integer': case 'float': case 'decimal':
      return 'number';
    case 'boolean':
      return 'boolean';
    case 'enumeration':
      return enumName(ct, attrName);
    default: // json, blocks, media, relation, component, dynamiczone: not modelled in this generator yet
      return 'unknown';
  }
}

function phpType(ct, attrName, attr) {
  switch (attr.type) {
    case 'string': case 'text': case 'richtext': case 'email': case 'password': case 'uid':
    case 'date': case 'datetime': case 'time': case 'timestamp': case 'biginteger':
      return 'string';
    case 'integer':
      return 'int';
    case 'float': case 'decimal':
      return 'float';
    case 'boolean':
      return 'bool';
    case 'enumeration':
      return enumName(ct, attrName);
    default:
      return 'mixed';
  }
}

// ---------------------------------------------------------------------------------------------
// TypeScript

const tsHeader = (sources) =>
  `// @generated by generator/generate.mjs from ${sources.join(', ')}. Do not edit; run \`npm run generate\`.\n`;

function tsContentTypes() {
  let out = tsHeader(contentTypes.map((ct) => ct.source)) + '\n';
  for (const ct of contentTypes) {
    out += `export const ${constCase(ct.className)}_UID = '${ct.uid}' as const;\n\n`;
    for (const [name, attr] of Object.entries(ct.schema.attributes)) {
      if (attr.type !== 'enumeration') continue;
      const list = constCase(enumName(ct, name));
      out += `/** Values of \`${name}\` in schema order. */\n`;
      out += `export const ${list} = [${attr.enum.map((v) => `'${v}'`).join(', ')}] as const;\n`;
      out += `export type ${enumName(ct, name)} = (typeof ${list})[number];\n\n`;
    }
    out += `export interface ${ct.className} {\n`;
    for (const [name, attr] of fieldsOf(ct)) {
      const type = tsType(ct, name, attr);
      out += `  ${name}: ${type}${attr.required || type === 'unknown' ? '' : ' | null'};\n`;
    }
    out += `}\n\n`;
  }
  return out.trimEnd() + '\n';
}

function tsHandlers() {
  let out = tsHeader(routeFiles.map((f) => `server/src/routes/${f}`)) + '\n';
  out += `/** Every action the routes point at, per controller. */\n`;
  out += `export interface RouteHandlers {\n`;
  for (const c of controllers) out += `  ${camel(c.name)}: ${c.actions.map((a) => `'${a}'`).join(' | ')};\n`;
  out += `}\n\n`;
  out += `/** A controller must implement every action its routes name (checked with \`satisfies\`). */\n`;
  out += `export type ControllerContract<C extends keyof RouteHandlers> = Record<RouteHandlers[C], (ctx: any) => unknown>;\n`;
  return out;
}

// ---------------------------------------------------------------------------------------------
// PHP: one class per file, kebab-case file names (strapi-php conventions)

const phpHeader = (sources, ns) =>
  `<?php\n\n// @generated by generator/generate.mjs from ${sources.join(', ')}. Do not edit; run \`npm run generate\`.\n\ndeclare(strict_types=1);\n\nnamespace ${ns};\n`;

const kebab = (s) => s.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();

function phpEnum(ct, attrName, attr) {
  const name = enumName(ct, attrName);
  let out = phpHeader([ct.source], `${phpNamespace}\\Generated`) + '\n';
  out += `/** Values of \`${attrName}\`; \`cases()\` returns them in schema order. */\n`;
  out += `enum ${name}: string\n{\n`;
  for (const value of attr.enum) out += `    case ${studly(value)} = ${phpString(value)};\n`;
  out += `}\n`;
  return { file: `${kebab(name)}.php`, content: out };
}

function phpFromArrayExpr(ct, name, attr) {
  const type = phpType(ct, name, attr);
  const key = `$row[${phpString(name)}]`;
  const cast = {
    string: `(string) ${key}`,
    int: `(int) ${key}`,
    float: `(float) ${key}`,
    bool: `(bool) ${key}`,
    mixed: key,
  }[type] ?? `${type}::from((string) ${key})`;
  if (type === 'mixed') return `${key} ?? null`;
  return attr.required ? cast : `isset(${key}) ? ${cast} : null`;
}

function phpClass(ct) {
  let out = phpHeader([ct.source], `${phpNamespace}\\Generated`) + '\n';
  out += `/** A \`${ct.uid}\` entry as the document service returns it. */\n`;
  out += `final readonly class ${ct.className}\n{\n`;
  out += `    public const string UID = ${phpString(ct.uid)};\n\n`;
  out += `    public function __construct(\n`;
  for (const [name, attr] of fieldsOf(ct)) {
    const type = phpType(ct, name, attr);
    const nullable = !attr.required && type !== 'mixed' ? '?' : '';
    out += `        public ${nullable}${type} $${name},\n`;
  }
  out += `    ) {\n    }\n\n`;
  out += `    /** @param array<string, mixed> $row */\n`;
  out += `    public static function fromArray(array $row): self\n    {\n        return new self(\n`;
  for (const [name, attr] of fieldsOf(ct)) out += `            ${name}: ${phpFromArrayExpr(ct, name, attr)},\n`;
  out += `        );\n    }\n\n`;
  out += `    /** @return array<string, mixed> wire format: enums as their string values */\n`;
  out += `    public function toArray(): array\n    {\n        return [\n`;
  for (const [name, attr] of fieldsOf(ct)) {
    const value = attr.type === 'enumeration' ? `$this->${name}${attr.required ? '' : '?'}->value` : `$this->${name}`;
    out += `            ${phpString(name)} => ${value},\n`;
  }
  out += `        ];\n    }\n}\n`;
  return { file: `${kebab(ct.className)}.php`, content: out };
}

function phpHandlers() {
  let out = phpHeader(routeFiles.map((f) => `server/src/routes/${f}`), `${phpNamespace}\\Generated`) + '\n';
  out += `/** Every action the routes point at, per controller. */\n`;
  out += `final class RouteHandlers\n{\n`;
  out += `    /** @var array<string, list<string>> */\n`;
  out += `    public const array CONTROLLERS = [\n`;
  for (const c of controllers) out += `        ${phpString(c.name)} => [${c.actions.map(phpString).join(', ')}],\n`;
  out += `    ];\n}\n`;
  return { file: 'route-handlers.php', content: out };
}

// ---------------------------------------------------------------------------------------------
// assemble outputs

const outputs = new Map();
const tsTypes = tsContentTypes();
outputs.set('server/src/generated/content-types.ts', tsTypes);
outputs.set('server/src/generated/route-handlers.ts', tsHandlers());
outputs.set('admin/src/generated/content-types.ts', tsTypes);
for (const ct of contentTypes) {
  for (const [name, attr] of Object.entries(ct.schema.attributes)) {
    if (attr.type === 'enumeration') {
      const { file, content } = phpEnum(ct, name, attr);
      outputs.set(`server/src/generated/${file}`, content);
    }
  }
  const { file, content } = phpClass(ct);
  outputs.set(`server/src/generated/${file}`, content);
}
{
  const { file, content } = phpHandlers();
  outputs.set(`server/src/generated/${file}`, content);
}

// ---------------------------------------------------------------------------------------------
// parity: every hand-written server file exists in both languages, same path, same name

function walk(dir) {
  return readdirSync(join(root, dir)).flatMap((name) => {
    const path = `${dir}/${name}`;
    return statSync(join(root, path)).isDirectory() ? walk(path) : [path];
  });
}

const problems = [];

const serverFiles = walk('server/src').filter((p) => !p.startsWith('server/src/generated/'));
const stems = (ext) => new Set(serverFiles.filter((p) => extname(p) === ext).map((p) => p.slice(0, -ext.length)));
const tsStems = stems('.ts');
const phpStems = stems('.php');
for (const stem of tsStems) if (!phpStems.has(stem)) problems.push(`parity: ${stem}.ts has no ${stem}.php`);
for (const stem of phpStems) if (!tsStems.has(stem)) problems.push(`parity: ${stem}.php has no ${stem}.ts`);

if (composer.extra.strapi.name !== pluginName) {
  problems.push(`manifest: composer.json extra.strapi.name "${composer.extra.strapi.name}" != package.json strapi.name "${pluginName}"`);
}
if (composer.version !== pkg.version) {
  problems.push(`manifest: composer.json version ${composer.version} != package.json version ${pkg.version} (versions move in lockstep)`);
}

// ---------------------------------------------------------------------------------------------
// write or check

for (const dir of ['server/src/generated', 'admin/src/generated']) {
  if (!existsSync(join(root, dir))) mkdirSync(join(root, dir), { recursive: true });
  for (const name of readdirSync(join(root, dir))) {
    const path = `${dir}/${name}`;
    if (!outputs.has(path)) {
      if (check) problems.push(`stale: ${path} is not generated any more`);
      else unlinkSync(join(root, path));
    }
  }
}

for (const [path, content] of outputs) {
  const abs = join(root, path);
  const current = existsSync(abs) ? readFileSync(abs, 'utf8') : null;
  if (current === content) continue;
  if (check) problems.push(`stale: ${path} is out of date`);
  else {
    writeFileSync(abs, content);
    console.log(`wrote ${relative(root, abs)}`);
  }
}

if (problems.length) {
  for (const p of problems) console.error(p);
  if (check) console.error('\nRun `npm run generate`, and add the missing counterpart files.');
  process.exit(1);
}
if (check) console.log(`ok: ${outputs.size} generated files current, ${tsStems.size} server files in parity`);
