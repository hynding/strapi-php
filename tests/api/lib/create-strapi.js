'use strict';

/**
 * `createStrapi()` of @strapi/strapi's build, which plugins/graphql/cors imports directly to load
 * the app with its own config files. Loads the PHP test app with lib/strapi.js instead: the
 * suite's `config/<name>.js` (CommonJS, written by the test) become the app's `config/<name>.php`
 * before the worker starts, plugins merged over the template's (lib/jest-setup.js restores the
 * template config before the next file).
 */
const fs = require('fs');
const path = require('path');
const { createStrapiInstance } = require('./strapi');
const { apiTestsDir } = require('./paths');

const CONFIGS = { middlewares: false, plugins: true }; // name → merged over the template's

/** a CommonJS config file as it is now (the test rewrites it between instances) */
const loadJs = (file) => {
  const module = { exports: {} };
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', fs.readFileSync(file, 'utf8'))(module, module.exports, require);
  return module.exports;
};

const writePhpConfig = (appDir) => {
  for (const [name, merge] of Object.entries(CONFIGS)) {
    const js = path.join(appDir, 'config', `${name}.js`);
    const php = path.join(appDir, 'config', `${name}.php`);
    const template = path.join(apiTestsDir, 'app', 'config', `${name}.php`);
    if (!fs.existsSync(js)) {
      fs.copyFileSync(template, php);
      continue;
    }
    const json = JSON.stringify(loadJs(js));
    const value = merge
      ? `array_replace_recursive((static fn (mixed $c): array => is_callable($c) ? $c() : $c)(require ${JSON.stringify(template)}), $config)`
      : '$config';
    fs.writeFileSync(
      php,
      `<?php\n\ndeclare(strict_types=1);\n\n// written by tests/api/lib/create-strapi.js from config/${name}.js\n` +
        `$config = json_decode(<<<'JSON'\n${json}\nJSON, true, 512, JSON_THROW_ON_ERROR);\n\nreturn ${value};\n`
    );
  }
};

const createStrapi = ({ appDir }) => {
  let instance = null;
  const strapi = {
    log: { level: 'warn' },
    // content-api auth is bypassed already (createStrapiInstance's bypassAuth)
    get: (name) => (name === 'auth' ? { register() {} } : instance.get(name)),
    server: {
      listen: async () => {},
      get httpServer() {
        return instance.server.httpServer;
      },
    },
    async load() {
      writePhpConfig(appDir);
      instance = await createStrapiInstance({ ensureSuperAdmin: false });
      return strapi;
    },
    async destroy() {
      await instance?.destroy();
      for (const name of Object.keys(CONFIGS)) {
        fs.copyFileSync(path.join(apiTestsDir, 'app', 'config', `${name}.php`), path.join(appDir, 'config', `${name}.php`));
      }
    },
  };
  return strapi;
};

module.exports = { createStrapi };
