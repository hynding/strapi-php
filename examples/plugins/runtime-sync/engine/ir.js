'use strict';

/**
 * Mirrors engine/ir.php: the intermediate form of a config file, the contract between the
 * evaluators (one per language) and the emitters (one per language). See ir.php for the shape.
 * Objects are plain objects here, lists are arrays.
 */
const ENV_TYPES = ['string', 'int', 'float', 'bool', 'json', 'array', 'date', 'oneOf'];

function env(type, key, defaultValue = undefined, hasDefault = false, expected = null) {
  const ref = { $env: type, key };
  if (hasDefault) ref.default = defaultValue;
  if (expected !== null) ref.expected = expected;
  return ref;
}

function concat(parts) {
  const merged = [];
  for (const part of parts) {
    if (typeof part === 'string' && merged.length > 0 && typeof merged[merged.length - 1] === 'string') merged[merged.length - 1] += part;
    else merged.push(part);
  }
  return { $concat: merged };
}

const root = () => ({ $root: true });
const todo = (source, lang) => ({ $todo: source, lang });

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const isEnv = (v) => isObject(v) && '$env' in v;
const isConcat = (v) => isObject(v) && '$concat' in v;
const isRoot = (v) => isObject(v) && '$root' in v;
const isTodo = (v) => isObject(v) && '$todo' in v;
const isLeaf = (v) => isEnv(v) || isConcat(v) || isRoot(v) || isTodo(v);

function any(value, predicate) {
  if (predicate(value)) return true;
  if (isConcat(value)) return value.$concat.some((part) => any(part, predicate));
  if (isEnv(value) && 'default' in value) return any(value.default, predicate);
  if (isObject(value) && !isLeaf(value)) return Object.values(value).some((child) => any(child, predicate));
  if (Array.isArray(value)) return value.some((child) => any(child, predicate));
  return false;
}

const usesEnv = (value) => any(value, isEnv);
const usesRoot = (value) => any(value, isRoot);

function countTodos(value) {
  let count = 0;
  any(value, (v) => {
    if (isTodo(v)) count++;
    return false;
  });
  return count;
}

/** A document, decoded from the JSON the other runtime's evaluator printed. */
function decode(json) {
  const doc = JSON.parse(json);
  if (!isObject(doc) || !('form' in doc)) throw new Error(`Not a config IR document: ${json.slice(0, 200)}`);
  return { source: String(doc.source ?? ''), form: String(doc.form), value: doc.value ?? null, warnings: (doc.warnings ?? []).map(String) };
}

const encode = (doc) => JSON.stringify(doc);

module.exports = { ENV_TYPES, env, concat, root, todo, isObject, isEnv, isConcat, isRoot, isTodo, isLeaf, usesEnv, usesRoot, countTodos, decode, encode };
