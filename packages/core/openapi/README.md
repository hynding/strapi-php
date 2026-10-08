# strapi/openapi

OpenAPI document generation from route schemas (port of @strapi/openapi)

| | |
| --- | --- |
| Upstream | [`@strapi/openapi`](https://github.com/strapi/strapi/tree/develop/packages/core/openapi) |
| Namespace | `Strapi\Openapi\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the repository root
for the naming rules. Classes keep upstream's exported names (`assemblers/document/info.php` holds
`DocumentInfoAssembler`).

## Usage

```php
use Strapi\Openapi\Exports;

['document' => $document, 'durationMs' => $ms] = Exports::generate($strapi, ['type' => 'content-api']); // or 'admin'
```

From the CLI: `bin/strapi openapi:generate [-o specification.json]` (upstream's
`strapi openapi generate`). Over HTTP: `server.openapi` in `config/server.php`
(`'content-api' => ['access' => 'public']`, `'admin' => ['access' => 'authenticated']`, see
`strapi/core`'s `services/server/openapi.php`).

The document is built from the routes the server registered (`strapi.apis`, `strapi.plugins`,
`strapi.admin` routers), filtered by `info.type`: operation IDs, tags, path and query parameters,
request bodies and `200` responses come from the routes' Zod schemas (`request.params`,
`request.query`, `request.body`, `response`), converted with `Strapi\Utils\Zod::toJSONSchema()`
(`draft-2020-12`, `io: output`) against the content-API schema registry
(`strapi.contentAPISchemaRegistry`), whose entries end up in `components.schemas`.

## Ported files

| Upstream | PHP |
| --- | --- |
| `exports.ts` | `src/exports.php` — `Exports::generate($strapi, ['type' => ...])` |
| `constants.ts`, `types.ts` | `src/constants.php`, `src/types.php` (`@phpstan-type` aliases) |
| `generator/{generator,types}.ts` | `src/generator/{generator,types}.php` — `OpenAPIGenerator` |
| `assemblers/types.ts` | `src/assemblers/types.php` — the `Assembler\{Assembler,Document,Path,PathItem,Operation}` interfaces (upstream's `Assembler` namespace, one file) |
| `assemblers/document/{factory,info,metadata,security,server,query-param-styles}.ts` | same paths — `DocumentAssemblerFactory`, `DocumentInfoAssembler`, `DocumentMetadataAssembler`, `DocumentSecurityAssembler`, `DocumentServerAssembler`, `QueryParamStyles` |
| `assemblers/document/path/{factory,path}.ts` | same paths — `PathAssemblerFactory`, `DocumentPathsAssembler` |
| `assemblers/document/path/path-item/{factory,path-item}.ts` | same paths — `PathItemAssemblerFactory`, `PathItemAssembler` |
| `assemblers/document/path/path-item/operation/*.ts` | same paths — `OperationAssemblerFactory`, `OperationAssembler`, `OperationIDAssembler`, `OperationParametersAssembler`, `OperationResponsesAssembler`, `OperationTagsAssembler`, `BodyAssembler` |
| `context/types.ts`, `context/factories/*.ts` | `src/context/types.php` (`Context`), `src/context/factories/*.php` (`AbstractContextFactory`, `DocumentContextFactory`, `PathContextFactory`, `PathItemContextFactory`, `OperationContextFactory`) |
| `pre-processor/*`, `post-processor/*` | same paths — `PreProcessor`, `PreProcessorFactory`, `PostProcessor`, `PostProcessorsFactory`, `ComponentsWriter` |
| `registries/{factory,types}.ts` | same paths — `RegistriesFactory`, `Registries` (`extractedComponentSchemas`, an `\ArrayObject` shared by reference) |
| `routes/{collector,matcher,types}.ts`, `routes/rules/is-of-type.ts`, `routes/providers/*.ts` | same paths — `RouteCollector`, `RouteMatcher`, `IsOfType::isOfType()`, `AbstractRoutesProvider` (an `IteratorAggregate`), `AdminRoutesProvider`, `ApiRoutesProvider`, `PluginRoutesProvider`, `RoutesProvider` |
| `utils/{debug,zod}.ts`, `utils/timer/*` | same paths — `Debug::createDebugger()` (the `debug` package: `DEBUG=strapi:core:openapi*`), `Zod::zodToOpenAPI()` / `liftZodSharedDefinitions()` / `stripJsonSchemaId()` / `toComponentsPath()`, `Timer`, `TimerFactory` |

Barrel `index.ts` files are not ported.

### PHP-port additions

| File | Why |
| --- | --- |
| `src/context/context-output.php` | `ContextOutput` (`{ data, stats }`) as a class, so assemblers fill `$context->output->data` in place. |

## Deviations

| Upstream | Here |
| --- | --- |
| `Core.Strapi` mocks in tests | `strapi` is typed structurally (`object`, PHPDoc `Strapi`): anything with the methods the assemblers call. |
| JSON objects | Plain arrays; an empty JSON object is a `\stdClass` (`paths: {}`, `properties: {}`, `{}` schemas). |
| Route validators | A route's `request.params` / `request.query` entry that is not a Zod schema (a PHP callable, a `null` placeholder) is documented with an empty schema (`{}`), required for a path parameter. `contentAPI.addInputParams()` bodies (`['shape' => [...]]` in PHP) are converted as `z.object(shape)`, a non-Zod validator standing as `z.unknown().optional()`. |
| `OperationIDAssembler` | Keeps upstream's module-level `/g` regex state (`lastIndex`) when reading `:param` segments. |
| `Zod::zodToOpenAPI()` | When the route schema is itself registered in the content-API registry, PHP's registry keeps one id per schema object: the registered schema's output is returned (what zod emits for the uuid entry). |

## Tests

`tests/` ports `__tests__` to PHPUnit (testsuite `openapi`): `DocumentAssemblersTest`,
`OperationAssemblersTest` (+ a `shape` body case), `QueryParamStylesTest`, `ZodToOpenapiTest`,
`Routes/{ApiRoutesProviderTest,CollectorTest,PluginsRouteProviderTest,RouteMatcherTest}`, with
`Fixtures/Routes`, `Mocks/*` and `Helpers/ContentApiSchemaRegistry`.

Upstream's API test `tests/api/core/strapi/api/openapi-access.test.api.ts` passes (4/4).

## Node vs PHP

The same app (upstream `examples/getstarted`: its content types, components and the extra
`extraParam` / `clientMutationId` Zod params) booted on Strapi 5.56.0 and on this port gives
identical `content-api` and `admin` documents, key order included, except:

- `x-strapi-version` (the PHP release is `5.56.0-beta.1`);
- the `publishedAt` defaults (`new Date()` at boot);
- routes that only exist on one side: the PHP getstarted's `/temps/ping` and `/temps/denied`; the
  content manager's preview routes (`/content-manager/preview/*`, not ported in
  `strapi/content-manager`) in the admin document.
