'use strict';

/**
 * Server entry: assembles the plugin module. Mirrors index.php line for line.
 *
 * Strapi loads it through package.json's `./strapi-server` export, which points at
 * dist/server/index.js: `strapi-plugin build` copies this file there without bundling the
 * requires (CommonJS outside node_modules is left as-is), so the paths below go up to the
 * package root and back into server/src. They resolve the same from here and from dist/server.
 */
const register = require('../../server/src/register');
const config = require('../../server/src/config');
const controllers = require('../../server/src/controllers');
const routes = require('../../server/src/routes');
const services = require('../../server/src/services');

module.exports = { register, config, controllers, routes, services };
