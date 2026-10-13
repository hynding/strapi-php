'use strict';

/** Mirrors engine/spec.php: reads spec/facets.json, the pairing rules both runtimes share. */
const fs = require('node:fs');
const path = require('node:path');
const glob = require('./glob');

const file = () => path.join(__dirname, '..', 'spec', 'facets.json');

let spec = null;
const load = () => {
  if (!spec) spec = JSON.parse(fs.readFileSync(file(), 'utf8'));
  return spec;
};

const extensions = () => load().extensions;

/** The runtime a file extension belongs to: 'node', 'php' or null. */
const runtimeOfExtension = (extension) => {
  for (const [runtime, list] of Object.entries(extensions())) {
    if (list.includes(extension)) return runtime;
  }
  return null;
};

const ignored = () => load().ignore;
const paired = () => load().paired;
const shared = () => load().shared;
const unsupported = () => load().unsupported;
const manifests = () => load().manifests;

/** The paired facet a stem (path without extension) belongs to. */
const pairedFacet = (stem) => paired().find((facet) => glob.match(facet.glob, stem)) || null;
const sharedFacet = (p) => shared().find((facet) => glob.match(facet.glob, p)) || null;
const unsupportedFacet = (p) => unsupported().find((facet) => glob.match(facet.glob, p)) || null;

const isIgnored = (p) => ignored().some((prefix) => p === prefix || p.startsWith(`${prefix}/`));

module.exports = { file, load, extensions, runtimeOfExtension, ignored, paired, shared, unsupported, manifests, pairedFacet, sharedFacet, unsupportedFacet, isIgnored };
