# strapi/core

Strapi runtime: container, registries, loaders, providers, middlewares, document service, entity
validator, core API (port of `@strapi/core`).

| | |
| --- | --- |
| Upstream | [`@strapi/core`](https://github.com/strapi/strapi/tree/develop/packages/core/core) |
| Namespace | `Strapi\Core\` |
| Status | `foundation` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the repository root
for the naming rules. Everything is synchronous: where upstream `await`s, we call.

## Usage

```php
use Strapi\Core\Core;

$strapi = Core::createStrapi(['appDir' => __DIR__])->load();   // register() + bootstrap()

// Koa/PSR-7: one request in, one response out (FPM, FrankenPHP worker mode, tests)
$response = $strapi->server()->handle($psr7ServerRequest);

// or serve with PHP's built-in web server (`strapi start`)
$strapi->start();

$articles = $strapi->documents('api::article.article');
$article = $articles->create(['data' => ['title' => 'Hello', 'categories' => ['connect' => ['abc123...']]]]);
$articles->publish(['documentId' => $article['documentId']]);
$page = $articles->findMany(['status' => 'published', 'filters' => ['title' => ['$contains' => 'Hello']], 'populate' => ['categories'], 'limit' => 10]);
```

## Boot sequence

`Core::createStrapi($options)` resolves the working directories (`appDir` = `distDir`, there is no
TypeScript build), constructs `Strapi` and registers it as the global instance (`Core::instance()`).
The constructor loads the configuration (`config/*.php`, `.env`, `composer.json` → `info`,
`server`/`admin` URLs), creates the logger and the internal services (`config`, `query-params`,
`content-api`, `auth`, `server`, `fs`, `eventHub`, `startupLogger`, `fetch`, `features`,
`requestContext`, `customFields`, `entityValidator`, `entityService`, `documents`, `localization`,
`db`, `reload`) and runs the providers' `init`.

`load()` = `register()` + `bootstrap()`:

1. **register** — providers `register` (registries, admin stub, core-store, cron, session-manager,
   telemetry, webhooks, content-structure, ai, mcp): the *registries* provider runs the loaders
   (`src/index.php`, sanitizers, validators, plugins, admin, apis, middlewares, components, policies)
   which fill `content-types`, `components`, `services`, `controllers`, `policies`, `middlewares`,
   `hooks`, `apis`, `plugins`; then the modules' `register`, the user `register` of `src/index.php`,
   custom field types are converted to their underlying type, the model cache is cleared and the
   content API permission map is refreshed.
2. **bootstrap** — `db.init([...contentTypes, ...components, ...models])`, the previous schema is read
   from the core store (`oldContentTypes`), `strapi::content-types.beforeSync` runs
   (draft/publish, first-published-at and i18n migrations `disable` side), `db.schema.sync()`
   (migrations + diff + DDL), `db.repair` when the schema changed, the component join-table cleanup,
   `strapi::content-types.afterSync` (migrations `enable` side), the schema is stored, the
   middlewares (`config/middlewares.php`) and routes (`api.rest.prefix`, content-api and admin
   APIs, plugin and API routers) are mounted, the content API actions are registered as permissions,
   the modules' and providers' `bootstrap`, the user `bootstrap`.

`start()` loads then `listen()`s (built-in web server); `destroy()` runs the providers' and modules'
`destroy` and closes the database; `stop()` exits (or throws when `STRAPI_NO_EXIT=1` / non-CLI).

## Request pipeline (`services/server`)

`Server::handle(ServerRequestInterface): ResponseInterface` builds a `Context` (Koa semantics: 404
until a body or status is set, `null` body → 204, `ctx.notFound()`/`badRequest()`... write the
`{ data: null, error }` envelope), runs the global middlewares composed like koa-compose, then the
router: `Router` compiles the Koa paths (`/articles/:id`, `/admin/:path*`, `/((?!uploads/).+)`) to
`nikic/fast-route`, answers 405 with `Allow` like `@koa/router`'s `allowedMethods()`, and dispatches
the per-route chain of `ComposeEndpoint`: route info → `strapi.auth.authenticate` →
`strapi.auth.verify` → policies → route middlewares → return-body → controller action.
`Server::listen(host, port)` runs `php -S` on `public/index.php` (or a generated router) through
symfony/process.

## Ported files

| Upstream | PHP | Public API |
| --- | --- | --- |
| `index.ts`, `compile.ts` | `src/index.php`, `src/compile.php` | `Core::createStrapi(array $options): Strapi`, `Core::instance()`, `Compile::compileStrapi()`. |
| `Strapi.ts` | `src/Strapi.php` | `Strapi` (final, extends `Container`, implements `Strapi\Types\Core\Strapi`): accessors `env()`, `dirs()`, `config()`, `db()`, `log()`, `server()`, `auth()`, `contentAPI()`, `eventHub()`, `cron()`, `store()`, `fs()`, `fetch()`, `features()`, `requestContext()`, `customFields()`, `entityValidator()`, `entityService()`, `documents($uid)`, `documentService()`, `localization()`, `sessionManager()`, `telemetry()`, `startupLogger()`, `reload()`, `admin()`, `ai()`, `EE()`; registries `services()/service()`, `controllers()/controller()`, `contentTypes()/contentType()`, `components()`, `policies()/policy()`, `middlewares()/middleware()`, `plugins()/plugin()/hasPlugin()`, `hooks()/hook()`, `apis()/api()`, `sanitizers()`, `validators()`; lifecycle `start()`, `load()`, `register()`, `bootstrap()`, `listen()`, `destroy()`, `stop()`, `stopWithError()`; `getModel($uid)`, `query($uid)`, `isLoaded()`. |
| `container.ts` | `src/container.php` | `Container`: `add(name, resolver)`, `get(name, args)`, `has()`, `set()` — Closure resolvers run once and are memoized. |
| `configuration/*` | `src/configuration/*` | `Configuration::loadConfiguration($opts)`, `loadEnv()`, `version()`; `ConfigLoader::loadConfigDir()` (restricted/mistaken file names); `GetDirs::getDirs()`; `Urls::getConfigUrls()`, `getAbsoluteAdminUrl()`, `getAbsoluteServerUrl()`; `ServerConfig::defaults()`, `warnDeprecatedServerConfig()`. |
| `registries/*` | `src/registries/*` | `Apis`, `Plugins`, `Modules`, `Components`, `ContentTypes` (`add(namespace, definitions)`, `extend(uid, fn)`), `Controllers`/`Services` (lazy factories `callable(Strapi)`, `extend()`), `Policies` (`get`, `resolve(config, namespaceInfo)`), `Middlewares`, `Hooks`, `Models`, `Sanitizers`, `Validators`, `CustomFields` (`add`, `get`, `getAll`), `Namespace_::hasNamespace/addNamespace/removeNamespace`; `ActionMap` wraps array-shaped controllers/services. |
| `domain/*` | `src/domain/*` | `ContentType\ContentType::createContentType($uid, $definition)` (schema.json → `Strapi\Types\Schema\Schema` with the default attributes, `getGlobalId()`), `ContentType\Validator::validateContentTypeDefinition()`; `Module\Module::createModule($namespace, $rawModule, $strapi)` with `load()`, `register()`, `bootstrap()`, `destroy()`, `config($path)`, `routes()`, `controller()`, `service()`, `policy()`, `middleware()`, `contentType()`. |
| `loaders/*` | `src/loaders/*` | `Loaders::loadApplicationContext($strapi)`; `Apis`, `Components`, `Middlewares`, `Policies`, `Sanitizers`, `Validators`, `SrcIndex`, `Admin` (stub, registers the builtin `admin::user` / `plugin::upload.*` / users-permissions schemas), `Plugins\Plugins::loadPlugins()`, `Plugins\GetEnabledPlugins` (Composer packages with `extra.strapi.kind = "plugin"`, `config/plugins.php` `enabled`/`resolve`), `Plugins\GetUserPluginsConfig`. |
| `providers/*` | `src/providers/*` | `Provider` interface (`init`, `register`, `bootstrap`, `destroy`); `Providers::all()`; `Registries`, `Admin` (stub: mounts the admin static handler), `CoreStore` (`providers/coreStore.php`, upstream's file name), `Cron`, `SessionManager`, `Telemetry`, `Webhooks`, `ContentStructure`, `Ai`, `Mcp` (starts/stops the MCP server). |
| `services/config.ts` | `src/services/config.php` | `Config::createConfigProvider($initial, $logger)`: `get(path, default)`, `set()`, `has()`, `all()` with dot or array paths, `plugin::` namespaces, the `plugin.` deprecation warning. |
| `services/server/*` | `src/services/server/*` | `Server::createServer()`: `use(middleware)`, `routes(routes|router)`, `api(name)`, `mount()`, `initMiddlewares()`, `initRouting()`, `listRoutes()`, `handle(ServerRequestInterface): ResponseInterface`, `listen(host, port, onListen)`, `destroy()`; `Context` (implements `Strapi\Types\Core\Context`): `request()`, `method()`, `path()`, `url()`, `query()`, `params()`, `requestBody()`, `files()`, `state()`, `header()`/`get()`, `setHeader()`/`set()`, `status()`/`setStatus()`, `body()`/`setBody()`, `setType()`, `redirect()`, `throw()`, `send()`, `created()`, `deleted()`, every Koa error helper (`badRequest`, `unauthorized`, `forbidden`, `notFound`, `payloadTooLarge`... via `KoaMethods`), `toResponse()`; `State`; `Router` (`add`, `use`, `match`, `toFastRoute`, `joinPath`); `Compose::compose()`; `ComposeEndpoint`; `Policy::createPoliciesMiddleware()`; `Middleware::resolveMiddlewares()/resolveRouteMiddlewares()`; `Routing` (`validateRouteConfig`, `addRoutes`); `Api`, `AdminApi`, `ContentApi`; `RegisterMiddlewares` (defaults, required list); `RegisterRoutes`; `HttpServer` (`listen`, `requestFromGlobals()`, `emit()`); `AdminStaticHandler`. |
| `middlewares/*` | `src/middlewares/*` | Factories `__invoke(array $config, Strapi $strapi): ?callable` for `strapi::body` (koa-body: JSON, urlencoded, text, multipart with files and the `data` JSON field), `cors` (own `@koa/cors` port, `matchOrigin()`), `errors`, `favicon`, `ip`, `logger`, `poweredBy`, `responseTime`, `responses`, `compression`, `query` (`qs` with Strapi's limits), `session` (signed cookie), `public` (`PublicStatic`, koa-static `defer`), `security` (own helmet port: CSP, HSTS, frameguard, referrer, nosniff...); `Middlewares::all()`. |
| `services/auth/index.ts` | `src/services/auth/index.php` | `Auth`: `register(type, strategy)`, `authenticate(ctx, next)`, `verify(auth, config, routeType)`, `strategies()`. A route type with no registered strategy is **public** (the users-permissions / admin packages that register them are not ported yet). |
| `services/content-api/*` | `src/services/content-api/*` | `ContentApi`: `$permissions` (`Permissions`: `registerActions()`, `registerBoundAction()`, `getActionsMap()`, `$engine`, `$providers`), `sanitize` (`input`, `output`, `query`), `validate` (`input`, `query`), `refresh()`, `addQueryParams()`, `addInputParams()`, `getRoutesMap()`. |
| `services/document-service/*` | `src/services/document-service/*` | `DocumentService::createDocumentService($strapi)`: `__invoke($uid)`/`get($uid)` → `DocumentServiceInstance` (implements `Strapi\Types\Modules\Documents\Repository`: `findMany`, `findFirst`, `findOne`, `count`, `create`, `update`, `delete`, `clone`, `publish`, `unpublish`, `discardDraft`), `use(middleware)`, `utils`; `Repository` (validates params, wraps each action in a transaction, emits `entry.*` events on commit), `Entries`, `Components`, `DraftAndPublish` (`setStatusToDraft`, `defaultStatus`, `statusToLookup`, `statusToData`, `filterDataPublishedAt`), `Internationalization`, `Events`, `FirstPublishedAt`, `PublicationFilter`, `Params`, `Common`, `Transform\*` (`IdMap`, `Fields::transformFields`, `Populate::transformPopulate`, `Data`, `IdTransform::transformParamsDocumentId`, `Query`, `Relations\*`), `Attributes\*` (password hashing), `Middlewares\MiddlewareManager`, `Middlewares\Errors`, `Utils\*` (populate, relation sync, unidirectional/bidirectional/self-referential relations, component join-table cleanup, clone relations). |
| `services/entity-validator/*` | `src/services/entity-validator/*` | `EntityValidator`: `validateEntityCreation($model, $data, $options, $entity)`, `validateEntityUpdate(...)`, `buildRelationsStore()`, `checkRelationsExist()`; `Validators` (`string`, `email`, `uid`, `enumeration`, `integer`, `biginteger`, `float`, `date`/`time`/`datetime`/`timestamp`, `boolean`, `json`, `blocks`, unique checks against the database); `BlocksValidator`; `JsonLogic` (`conditions.visible`); the hand-written `yup` subset (`Yup`, `YupString`, `YupNumber`, `YupBoolean`, `YupArray`, `YupObject`, `YupLazy`, `Undefined`, `TestContext`, `YupError`) throwing `Strapi\Utils\Errors\YupValidationError` with upstream's messages and `details.errors[] = { path, message, name, value }`. |
| `core-api/*` | `src/core-api/*` | `Controller\Controller::createController()`, `Controller\Base` (`sanitizeQuery`, `sanitizeInput`, `sanitizeOutput`, `validateQuery`, `validateInput`, `transformResponse`), `Controller\CollectionType` (`find`, `findOne`, `create` 201, `update`, `delete` 204), `Controller\SingleType` (`find`, `update`, `delete`), `Controller\Transform::transformResponse()` (flat Strapi 5 entities; the v4 `attributes` shape with `useJsonAPIFormat` / `strapi-response-format: v4`); `Service\Service::createService()`, `Service\CoreService` (`getFetchParams`), `Service\CollectionType`, `Service\SingleType`, `Service\Pagination` (`getPaginationInfo`, `transformPaginationResponse`...); `Routes\Routes::createRoutes()`, `Routes\CoreRouter` (`only`, `except`, `config`, `prefix`); `Extendable` (the user overrides bound over the base object). |
| `core-api/routes/validation/*`, `utils/zod.ts` | `src/core-api/routes/validation/*`, `src/utils/zod.php` | The Zod request/response schemas of the core routes: `CoreContentTypeRouteValidator` (`document()`, `documents()`, `documentID()`, `body()`, `partialBody()`, `data()`, `queryParams([...])`, schema-aware `fields`/`populate`/`sort`/`filters`), `CoreComponentRouteValidator::entry()`, `AbstractCoreRouteValidator`, `Mappers` / `Attributes` (attribute → Zod, output and input), `Utils::safeSchemaCreation()` / `safeGlobalRegistrySet()` filling `SchemaRegistry` (`strapi.contentAPISchemaRegistry`, `Strapi::contentAPISchemaRegistry()`). Component entries build their attribute schemas after they are registered (upstream's lazy shape getters), so the registry order, and the OpenAPI `components.schemas` order, match Node. |
| `services/server/openapi.ts` | `src/services/server/openapi.php` | `Openapi::registerOpenAPIRoute($strapi)` (called by `RegisterRoutes`): the `server.openapi` endpoints (`content-api`: `public`; `admin`: `authenticated` through `admin::isAuthenticatedAdmin`), served by `strapi/openapi`'s `Exports::generate()` with the file cache (`.strapi/openapi/*.json`, `maxAgeMs`). `Openapi::$generate` replaces the generator in tests. |
| `factories.ts` | `src/factories.php` | `Factories::createCoreController($uid, $cfg)`, `createCoreService($uid, $cfg)`, `createCoreRouter($uid, $cfg)`, `isCustomController()`. `$cfg` is an array of closures or `callable(Strapi): array`; inside an override `$this` is the controller (`$this->strapi`, `$this->uid`, `$this->sanitizeQuery($ctx)`...). |
| `migrations/*` | `src/migrations/*` | `Migrations::enable($strapi, ['oldContentTypes', 'contentTypes'])` / `disable(...)`; `DraftPublish` (creates the published rows when D&P is enabled, drops drafts when disabled), `FirstPublishedAt`, `I18n` (`locale` backfill). |
| `services/*` | `src/services/*` | `CoreStore` / `ScopedCoreStore` (`strapi.store`), `EventHub` (`on`, `once`, `off`, `emit`, `subscribe`, `unsubscribe`, `destroy`), `Errors` (`formatApplicationError`, `formatHttpError`, `formatInternalError`), `Fs` (`writeAppFile`, `removeAppFile`, `appendFile`), `Features` (`futureIsEnabled`, `isEnabled`), `RequestContext` (`run`, `get`), `CustomFields`, `QueryParams` (`transform`), `Localization` (`isLocalizedContentType`, `getDefaultLocale`...), `Reloader`, `WorkerQueue` (synchronous), `Cron` (`add`, `remove`, `start`, `stop`, `jobs`, `runDue`, `loop`), `WebhookStore`, `WebhookRunner`, `SessionManager` (+ `session-manager/*` JWT session providers; tokens signed by `Utils\Jwt`, a small in-house HS/RS/ES implementation that, like upstream's `jsonwebtoken`, accepts secrets of any length — `firebase/php-jwt` 6.x is advisory-blocked on Packagist and 7.x rejects the 16-byte secrets Strapi generates), `EntityService` (deprecated wrapper over the document service), `Utils\DynamicZones`, `Utils\ConditionalFields`. |
| `services/mcp/**`, `mcp.ts` | `src/services/mcp/**`, `src/mcp.php` | `strapi.ai.mcp` (`Services\Mcp\Mcp`): `isEnabled()` (`server.mcp.enabled`), `isRunning()`, `registerTool()`, `registerPrompt()`, `registerResource()` (before start; names unique; `devModeOnly: true` or `auth.policies`), `start()`, `stop()`; the public builders `Strapi\Core\Mcp::defineTool/defineResource/definePrompt`. `POST /mcp` (Streamable HTTP, stateless, JSON-RPC 2.0, answered as `text/event-stream`), `GET|DELETE|PUT|PATCH /mcp` → JSON-RPC 405, the OAuth discovery fallback middleware; authentication with an admin token (`Authorization: Bearer`, `api-token-admin.authenticateAdminToken()`); per request an McpServer whose tools/prompts/resources are enabled from the token's ability (`syncMcpSessionCapabilities`, `devModeOnly` ones in `autoReload` only); the `log` dev tool; the MCP telemetry events. Definitions are arrays: `name`, `title`, `description`, `telemetry?`, `resolveInputSchema?: fn(context): ZodObject`, `resolveOutputSchema: fn(context): ZodObject`, `createHandler: fn(Strapi, ['userAbility' => Ability, 'user' => ['id' => …]]): fn(['args' => …, 'extra' => …]): ['content' => […], 'structuredContent' => […]]`. The MCP SDK subset (`@modelcontextprotocol/server`+`node` 2.0: McpServer, the stateless transport, `ProtocolError`) is `src/services/mcp/sdk/` (not upstream files). |
| `services/content-source-maps.ts` | `src/services/content-source-maps.php` | `strapi.get('content-source-maps')` (`ContentSourceMaps`): `encodeField()`, `encodeBlocks()`, `encodeEntry()`, `encodeSourceMaps()`; applied by `Transform::transformResponse()` when the request sends `strapi-encode-source-maps: true`. `vercelStegaCombine()` / `vercelStegaEncode()` port `@vercel/stega` 0.1.2 byte for byte. |
| `utils/*` | `src/utils/*` | `LoadConfigFile::loadConfigFile($file, $env)` (`.php` returning an array or `fn(EnvHelper): array`, `.json`), `LoadFiles`, `FilepathToPropPath`, `StartupLogger`, `Fetch` (stream-context HTTP client, proxy aware; `open()` streams the body, `Fetch::intercept()` answers requests instead of the network, for tests), `ConvertCustomFieldType`, `ResolveWorkingDirs`, `IsInitialized`, `Lifecycles`, `Signals` (pcntl), `TransformContentTypesToModels`, `Cron::shiftCronExpression`, `Ee` (always Community). |

## Stubbed

| Upstream | PHP | Why |
| --- | --- | --- |
| `loaders/admin.ts`, `providers/admin.ts` | stub | `@strapi/admin` is not ported: the loader registers the builtin schemas the database needs, the provider mounts `AdminStaticHandler` serving the upstream admin bundle (`.strapi/client`, `dist/build`, `node_modules/@strapi/admin/dist`). |
| `services/metrics/*` | `src/services/metrics/index.php` | Telemetry is a no-op (`isDisabled()` is true, `send()` returns false and logs `Telemetry is disabled: event … was not sent` at debug level, with the payload as context); `admin-user-hash`, `middleware`, `rate-limiter`, `sender`, `is-truthy` not ported (the PHP edition sends no telemetry). |
| `services/ai.ts`, `ai.ts` | `src/services/ai.php`, `src/ai.php` | AI feature flags only. |
| `services/content-structure/*` | `src/services/content-structure/index.php` | Returns the registered content types / components as upstream's `getContentTypes()`; the group validation (`validation.ts`, `utils/isGroupExpressionValid.ts`) is not ported. |
| `utils/update-notifier`, `utils/open-browser.ts` | no-ops | No npm registry check, no browser opening. |
| `services/reloader.ts` | no-op | PHP has no process to restart; sources are re-read per request. |
| `services/worker-queue.ts` | synchronous | Jobs run inline. |

## Skipped

| Upstream | Why |
| --- | --- |
| `ee/*` | Enterprise edition (license checks, SSO, audit logs) — `strapi.EE()` is always `false`. |
| `services/server/koa.ts` | Koa itself: replaced by `Context`, `Compose`, `Router` and PSR-7; `createKoaApp`'s custom response methods (`send`, `created`, `deleted`, the error helpers) are `Context` methods (parity alias to `services/server/context.php`). |
| `migrations/database/5.0.0-discard-drafts.ts`, `serialize-json-columns.ts` | The v4 → v5 discard-drafts data migration (registered as a no-op under its name by `strapi/database`'s `InternalMigrations::all()`); not ported yet. |

## Behaviour notes

- **Authentication**: no strategy is registered for the `content-api` and `admin` route types until
  the users-permissions / admin packages are ported, so every route is public unless its config
  says `auth: false`... which is also public. Registering any strategy with
  `$strapi->auth()->register('content-api', $strategy)` restores upstream's 401 behaviour.
- **Responses**: entities are flat (`id`, `documentId`, attributes); an empty `meta` serializes as
  `{}`; `delete` answers 204 even for an unknown document (upstream behaviour).
- **Custom fields**: `plugin::<p>.<name>` falls back to `global::<name>` when the plugin is not loaded.
- **Cron**: `strapi.cron.runDue()` runs the due jobs once (`bin/strapi cron:run` from crontab under
  FPM); `loop()` is the long-running scheduler for worker mode.

## Tests

`tests/` mirrors the upstream `__tests__` that apply: `ConfigTest`, `EventHubTest`, `CorsTest`
(`matchOrigin`), `DraftAndPublishTest`, `FieldsTest`, `TransformTest`, `EntityValidatorTest`
(the `index.test.ts` scenarios), `PoliciesTest`, plus `ContainerTest`, `RouterTest` and
`ContextTest` for the PHP-only pieces. The tests that need a `strapi` instance boot
`examples/getstarted` on an in-memory SQLite database (`BootedAppTestCase`). The HTTP behaviour is
covered by the repository's `tests/php` integration suite.

`tests/Services/Mcp/` ports `services/mcp/**/__tests__` (definition and capability registries,
configuration, session sync, server factory and tool discovery with strict JSON Schema 2020-12,
capability results, routes, authentication, `handlePost`, service lifecycle, OAuth fallback,
safe-handler wrappers, result translation, timeouts, metrics, the `log` tool): `strapi` is an
un-loaded getstarted instance with a recording logger, and the MCP client of upstream's in-memory
tests is `McpServer::handleMessage()`. `tests/Services/ContentSourceMapsTest.php` ports
`content-source-maps.test.ts` and checks the stega bytes against `@vercel/stega`. API:
`tests/api/core/mcp` passes except `mcp-audit-logs` (audit logs are Enterprise).

Deviations (MCP): PHP cannot interrupt a synchronous operation, so `connectTimeoutMs` /
`requestTimeoutMs` are checked when the operation returns (`WithTimeout`); the handler context
has no `signal`; a resource handler receives the URI as a string (JS: a `URL`).
