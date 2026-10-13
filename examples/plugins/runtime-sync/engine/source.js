'use strict';

/**
 * Mirrors engine/source.php: light-weight reading of source files without a full parser — comment
 * stripping, normalisation for template matching, and recognising the core-factory one-liners
 * (`createCoreController('api::x.x')`) that make up most of a project's `src/api`.
 */
const FACTORIES = { createCoreController: 'controller', createCoreService: 'service', createCoreRouter: 'router' };

/** Removes `//`, `#` (PHP) and `/* *\/` comments, leaving string literals alone. */
function stripComments(code, lang) {
  let out = '';
  let i = 0;
  while (i < code.length) {
    const char = code[i];
    const next = code[i + 1] ?? '';
    if (char === '"' || char === "'" || char === '`') {
      let end = i + 1;
      while (end < code.length && code[end] !== char) {
        if (code[end] === '\\') end++;
        end++;
      }
      out += code.slice(i, end + 1);
      i = end + 1;
      continue;
    }
    if (char === '/' && next === '*') {
      const end = code.indexOf('*/', i + 2);
      i = end === -1 ? code.length : end + 2;
      continue;
    }
    if ((char === '/' && next === '/') || (lang === 'php' && char === '#')) {
      const end = code.indexOf('\n', i);
      i = end === -1 ? code.length : end;
      continue;
    }
    out += char;
    i++;
  }
  return out;
}

/** Comments gone, whitespace collapsed, no spaces around punctuation: enough to recognise an unchanged template. */
const normalize = (code, lang) =>
  stripComments(code, lang)
    .replace(/\s+/g, ' ')
    .replace(/\s*([[\]{}(),:;=>])\s*/g, '$1')
    .trim();

/**
 * The core factory a controller / service / router file calls, if it is one of the one-liners
 * `strapi generate` writes. `plain` is false when the file customises the factory.
 */
function detectCoreFactory(code, lang) {
  const stripped = stripComments(code, lang);
  const m = /\b(createCore(?:Controller|Service|Router))\s*\(\s*(['"])([^'"]+)\2\s*(\)|,)/.exec(stripped);
  if (!m) return null;
  const [, factory, , uid, closing] = m;
  let plain = closing === ')';
  if (plain) {
    const rest = lang === 'php' ? withoutPhpBoilerplate(stripped, factory, uid) : withoutJsBoilerplate(stripped, factory, uid);
    plain = rest.trim() === '';
  }
  return { kind: FACTORIES[factory], factory, uid, plain };
}

const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

function withoutJsBoilerplate(code, factory, uid) {
  const q = escapeRegex(uid);
  const patterns = [
    /['"]use strict['"];?/g,
    new RegExp(`const\\s*\\{\\s*${factory}\\s*\\}\\s*=\\s*require\\(\\s*['"]@strapi\\/strapi['"]\\s*\\)\\.factories;?`, 'g'),
    /const\s*\{\s*factories\s*\}\s*=\s*require\(\s*['"]@strapi\/strapi['"]\s*\);?/g,
    /import\s*\{\s*factories\s*\}\s*from\s*['"]@strapi\/strapi['"];?/g,
    new RegExp(`(module\\.exports\\s*=|export\\s+default)\\s*(factories\\.)?${factory}\\(\\s*['"]${q}['"]\\s*\\);?`, 'g'),
  ];
  return patterns.reduce((text, pattern) => text.replace(pattern, ''), code);
}

function withoutPhpBoilerplate(code, factory, uid) {
  const q = escapeRegex(uid);
  const patterns = [
    /<\?php/g,
    /declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/g,
    /use\s+Strapi\\Core\\Factories\s*;/g,
    new RegExp(`return\\s+Factories::${factory}\\(\\s*['"]${q}['"]\\s*\\)\\s*;`, 'g'),
  ];
  return patterns.reduce((text, pattern) => text.replace(pattern, ''), code);
}

/** The header comment of a `strapi generate` file: `article controller`. */
const describe = (kind, uid) => `${uid.slice(uid.lastIndexOf('.') + 1)} ${kind}`;

module.exports = { FACTORIES, stripComments, normalize, detectCoreFactory, describe };
