'use strict';

/**
 * `@strapi/database` as upstream suites import it (core/database/knex-utils): `isKnexQuery(value)`
 * is true for a knex query or raw built on the instance's connection, here a bridge proxy on
 * `strapi.db.connection` / `strapi.db.getConnection()` that ends in a call (Strapi\ApiTests\Knex).
 */
const { chainOf } = require('./bridge');

const KNEX = ['connection', 'getConnection'];

const isKnexQuery = (value) => {
  const steps = chainOf(value);
  return (
    Array.isArray(steps) &&
    steps.length > 2 &&
    steps[0].get === 'db' &&
    KNEX.includes(steps[1].get) &&
    Object.prototype.hasOwnProperty.call(steps[steps.length - 1], 'call')
  );
};

module.exports = { isKnexQuery };
