'use strict';

/**
 * Mirrors engine/glob.php. The only glob syntax spec/facets.json uses: `*` matches within one
 * path segment, `**` matches any number of segments (including none). Paths are relative to the
 * project root, with `/` separators.
 */
const cache = new Map();

function toRegex(pattern) {
  if (cache.has(pattern)) return cache.get(pattern);
  let regex = '';
  for (const part of pattern.split(/(\*\*\/|\*\*|\*)/).filter((p) => p !== '')) {
    if (part === '**/') regex += '(?:.*/)?';
    else if (part === '**') regex += '.*';
    else if (part === '*') regex += '[^/]*';
    else regex += part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }
  const compiled = new RegExp(`^${regex}$`);
  cache.set(pattern, compiled);
  return compiled;
}

const match = (pattern, path) => toRegex(pattern).test(path);

module.exports = { match, toRegex };
