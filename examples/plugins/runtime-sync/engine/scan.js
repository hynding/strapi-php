'use strict';

/** Mirrors engine/scan.php: classifies every project file by the spec's facets. */
const files = require('./files');
const spec = require('./spec');

function run(root) {
  const pairs = new Map();
  const byFacet = {};
  let sharedCount = 0;
  const unsupported = [];

  for (const p of files.walk(root)) {
    const ext = files.extension(p);
    const runtime = spec.runtimeOfExtension(ext);
    const stem = files.stem(p);

    if (runtime !== null) {
      const facet = spec.pairedFacet(stem);
      if (facet) {
        if (!pairs.has(stem)) pairs.set(stem, { facet: facet.id, node: null, php: null });
        const pair = pairs.get(stem);
        if (pair[runtime] === null) pair[runtime] = p;
        continue;
      }
    }

    const shared = spec.sharedFacet(p);
    if (shared) {
      sharedCount++;
      byFacet[shared.id] = (byFacet[shared.id] ?? 0) + 1;
      continue;
    }

    const unsupportedFacet = spec.unsupportedFacet(p);
    if (unsupportedFacet) {
      // report the top directory the glob names (src/plugins/<name>), once
      const prefixSegments = unsupportedFacet.glob.split('**')[0].split('/').length - 1;
      const dir = p.split('/').slice(0, prefixSegments).join('/');
      if (!unsupported.includes(dir)) unsupported.push(dir);
    }
  }

  const paired = [...pairs.keys()].sort(files.compareStrings).map((stem) => {
    const pair = pairs.get(stem);
    return {
      facet: pair.facet,
      stem,
      node: pair.node,
      php: pair.php,
      state: pair.node === null ? 'missing-node' : pair.php === null ? 'missing-php' : 'paired',
    };
  });

  const missing = spec.shared().filter((facet) => facet.required && !(facet.id in byFacet)).map((facet) => facet.id);
  const sortedByFacet = Object.fromEntries(Object.keys(byFacet).sort(files.compareStrings).map((k) => [k, byFacet[k]]));

  return { paired, shared: { count: sharedCount, byFacet: sortedByFacet, missing }, unsupported };
}

module.exports = { run };
