# strapi/content-type-builder

Content-Type Builder: schema.json writer and API file generator (port of @strapi/content-type-builder server)

| | |
| --- | --- |
| Upstream | [`@strapi/content-type-builder`](https://github.com/strapi/strapi/tree/develop/packages/core/content-type-builder) |
| Namespace | `Strapi\ContentTypeBuilder\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules. The admin half (`admin/`) is upstream's React bundle.

## Port status

### Ported

| Area | Files |
| --- | --- |
| Module | `index`, `register` (Strapi AI CSP domains when `ai.admin` reports Strapi-managed AI), `bootstrap` (`plugin::content-type-builder.read` action), `config`, `middlewares/index`, `middlewares/is-development-mode` |
| Schema builder | `services/schema-builder/index` (`SchemaBuilder::createBuilder()`), `schema-handler`, `component-builder` and `content-type-builder` (traits mixed into `SchemaBuilder`, as upstream spreads them into the builder object), `types` (phpdoc only, no file) |
| Services | `content-types` (incl. `generateAPI`, see below), `components`, `component-categories`, `builder`, `api-handler`, `schema`, `schema-mutation`, `content-structure`, `constants`, `services/index` |
| Controllers | `builder`, `content-types`, `components`, `component-categories`, `schema`, `controllers/index` |
| Validation | `common`, `component`, `component-category`, `content-type`, `content-structure`, `data-transform`, `model-schema`, `relations`, `schema` (zod), `types` |
| Routes | `admin`, `content-api` (zod request/response schemas; a `callable` router factory in place of `createContentApiRoutesFactory`), `routes/index` |
| Utils | `index` (`Utils::getService`), `attributes`, `helpers`, `typeguards` |

Not an upstream file: `utils/pluralize.php`, the npm `pluralize` 8.0.0 rules the builder uses for
component collection names (`components_<category>_<pluralize(displayName)>`).

### Stubbed / deviations

| Upstream | State |
| --- | --- |
| `services/content-types.generateAPI` → `@strapi/generators` `content-type` | Calls `Strapi\Generators\Generators::generate('content-type', [... 'destination' => 'new', 'bootstrapApi' => true], ['dir' => <project root>])` (strapi/generators), which writes `content-types/<name>/schema.json`, then `controllers/`, `services/`, `routes/<name>.php` with `Factories::createCoreController/Service/Router('api::<name>.<name>')`. Like plop's `add` action it fails with `File already exists` rather than overwrite (upstream's `generate()` overwrites). |
| `strapi.reload()` after a write | see "Reload" below |
| `scheduleReloadAfterOutboundTelemetry` | telemetry is synchronous here; the reload is requested once the files are written |

## Files written

`schema.json` and component files are written as `fse.writeJSON(file, data, { spaces: 2 })`
writes them: `JSON.stringify(data, null, 2)` plus a trailing newline, same key order (the
builder keeps upstream's object-spread ordering), `/` and non-ASCII characters unescaped,
`undefined` properties left out and empty objects (`options`, `pluginOptions`, `attributes`, an
attribute, `conditions`...) written `{}`. A project can move between Strapi and strapi-php without
its schema files changing. JavaScript `undefined` is a missing key throughout the port; `null`
stays `null`.

## Reload

Upstream sets `strapi.reload.isWatching = false`, writes the files, then calls `strapi.reload()`,
which asks the `strapi develop` parent process to restart (`process.send('reload')`); without a
parent (the API test suite) it does nothing and the tests restart Strapi themselves.

The PHP reloader (`Strapi\Core\Services\Reloader`) only logs the request: there is no parent
process to signal. A schema change takes effect on the next boot of the PHP worker:

- **tests/api**: each `createStrapiInstance()` starts a fresh FrankenPHP process, which loads the
  files the previous instance wrote (and syncs the database schema), as upstream's restart does.
- **development** (`autoReload: true`, required by the `isDevelopmentMode` route middleware): run
  FrankenPHP with a file watcher on the project sources so the worker restarts after a save, e.g.
  `frankenphp php-server --worker public/index.php --watch 'src/**/*.{php,json}'`. Without a
  restart, the running worker keeps serving the old schema and `/update-schema-status` stays
  `isUpdating: true` (like upstream until its restart).
- **production** (`autoReload` not `true`): the write routes answer `PolicyError`, as upstream.

## Tests

`tests/` ports the upstream `__tests__` (validation, middleware, services, schema builder, utils).
The service mutation tests, which mock the schema builder upstream, run the real builder against a
scratch project (`tests/TempApp.php`) and check the files it leaves behind. Not ported: the
`content-structure` service case driving the admin panel's DataManager reducer (JavaScript).

Upstream's HTTP suite: `tests/api/core/content-type-builder` (see `tests/api/README.md`).
