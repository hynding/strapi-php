# strapi/plugin-documentation

Swagger UI documentation plugin (port of @strapi/plugin-documentation server)

| | |
| --- | --- |
| Upstream | [`@strapi/plugin-documentation`](https://github.com/strapi/strapi/tree/develop/packages/plugins/documentation) |
| Namespace | `Strapi\Plugin\Documentation\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules. The admin half (`admin/`, the settings page and the
"Documentation" menu) is the upstream npm package's: the admin bundle loads it when
`@strapi/plugin-documentation` is installed next to `@strapi/admin`.

## Usage

The plugin is enabled as soon as the Composer package is installed. Configure it like upstream in
`config/plugins.php`:

```php
'documentation' => [
    'config' => [
        'info' => ['version' => '1.0.0'],
        'x-strapi-config' => [
            'plugins' => ['upload', 'users-permissions'],                    // null: these two; []: none
            'mutateDocumentation' => static function (array &$draft): void { // or return the new document
                $draft['paths']['/custom'] = [...];
            },
        ],
        'servers' => [['url' => 'https://api.example.com/api', 'description' => 'Production']],
        'externalDocs' => [...],
        'security' => [['bearerAuth' => []]],
    ],
],
```

Outside production, `bootstrap` writes `src/extensions/documentation/documentation/<version>/full_documentation.json`
(the admin's "Regenerate" button calls `POST /documentation/regenerateDoc`). `GET /documentation`
(and `/documentation/v1.0.0`) serves Swagger UI with that document; when the settings enable
`restrictedAccess`, a password form (`/documentation/login`, bcrypt hash in the plugin store, a
`koa.sess` session cookie through `strapi::session`) guards it.

**Swagger UI assets**: `/plugins/documentation/*` serves the files of the `swagger-ui-dist` npm
package (upstream: `require('swagger-ui-dist').getAbsoluteFSPath()`), looked up in
`node_modules/swagger-ui-dist` from the project root upwards. Install it with the admin
dependencies: `npm install swagger-ui-dist@4.19.0` (without it the page loads but the assets 404,
and a warning is logged).

Other plugins add to the document with the `override` service:
`$strapi->plugin('documentation')->service('override')->registerOverride($spec, ['pluginOrigin' => 'upload', 'excludeFromGeneration' => ['upload']])`
(`$spec`: an array, or a YAML string). `strapi/upload` and `strapi/plugin-users-permissions` do so
in their `register`.

## Ported files

| Upstream | PHP |
| --- | --- |
| `index.ts`, `register.ts`, `bootstrap.ts` | `server/src/index.php` (the module), `register.php` (`Register`), `bootstrap.php` (`Bootstrap`, RBAC actions, plugin store defaults, generation outside production) |
| `config/{index,default-plugin-config}.ts` | `config/index.php`, `config/default-plugin-config.php` (`DefaultPluginConfig::defaultConfig()`) |
| `controllers/{index,documentation}.ts` | `controllers/index.php`, `controllers/documentation.php` — `getInfos`, `index`, `loginView`, `login`, `regenerateDoc`, `deleteDoc`, `updateSettings` |
| `middlewares/documentation.ts`, `middlewares/restrict-access.ts` | `middlewares/documentation.php` (`Documentation::addDocumentMiddlewares()`, `send()`, `getAbsoluteFSPath()`), `middlewares/restrict-access.php` (`RestrictAccess`, an invokable route middleware) |
| `routes/index.ts` | `routes/index.php` |
| `services/{index,documentation,override}.ts` | `services/index.php`, `services/documentation.php` (`Documentation`), `services/override.php` (`Override`) |
| `services/helpers/build-api-endpoint-path.ts`, `build-component-schema.ts` | same paths — `BuildApiEndpointPath` (with path-to-regexp 8.4.2's `parse()` and lodash's `_.set` path syntax inline), `BuildComponentSchema` |
| `services/helpers/utils/*.ts` | same paths — `CleanSchemaAttributes`, `GetApiResponses`, `GetSchemaData`, `LoopContentTypeNames`, `PascalCase`, `QueryParams`, `Routes` |
| `services/utils/get-plugins-that-need-documentation.ts` | same path — `GetPluginsThatNeedDocumentation` |
| `types.ts`, `utils.ts` | `types.php` (`@phpstan-type` aliases), `utils.php` (`Utils::getService()`) |
| `public/{index,login}.html` | copied as-is |

`services/helpers/index.ts` only re-exports and is not ported.

## Deviations

| Upstream | Here |
| --- | --- |
| The global `strapi` in the helpers | Passed explicitly (`buildApiEndpointPath($strapi, $api)`...); `RestrictAccess` reads `Core::instance()`. Services and helpers type `strapi` structurally (upstream tests pass mocks). |
| `dist/src/extensions` in production | The PHP port has no build step: `src/extensions` in every environment. |
| `immer`'s `produce` | Array copies; `mutateDocumentation` receives the draft by reference or returns the new document. |
| `yaml` (npm, YAML 1.2) | symfony/yaml, with plain timestamps kept as strings (YAML 1.2 has no timestamp type) and `{}` as `\stdClass`. |
| cheerio's `$('.error').text(...)` | The `.error` element's text is replaced in the template; cheerio also re-serializes the whole page (doctype, void elements, entities): the PHP page keeps the template's markup. |
| `bcryptjs` | `password_hash(PASSWORD_BCRYPT, cost 10)` / `password_verify()`. |
| koa-static | `Middlewares\Documentation::send()` (status 200, type by extension, `Cache-Control: max-age`, `Last-Modified`). |
| `_.camelCase` | `Strapi\Utils\Primitives\Strings::camelCase()` (transliterates `ü` to `ue` where lodash's `deburr` gives `u`). |

## Tests

`tests/` ports `__tests__` to PHPUnit (testsuite `documentation`): `Services/OverrideTest`
(+ YAML parsing), `Services/BuildComponentSchemaTest`, `Services/DocumentationTest` (the
generated document is written to a temporary `src/extensions` and read back; SwaggerParser's
validation is replaced by `$ref` resolution checks; the app-vs-dist path tests assert the PHP
layout), `Services/Helpers/Utils/QueryParamsTest`, plus `Services/Helpers/BuildApiEndpointPathTest`
(path-to-regexp `parse()` against the npm package's outputs). `Mocks/` holds upstream's
`__mocks__` data and a `global.strapi` stand-in.

Upstream's API suite has no documentation test; `tests/api/core/admin/admin-permission.test.api.js`
lists the plugin's four permissions, which the port registers.

## Node vs PHP

Booting upstream's `examples/getstarted` content types (with users-permissions, upload, i18n,
email, color-picker) on Strapi 5.56.0 and on this port writes byte-identical
`full_documentation.json` files (1.6 MB, both valid for `@apidevtools/swagger-parser`) except for
`info.x-generation-date` and the `timestamp` attributes' `example` (`Date.now()`).
