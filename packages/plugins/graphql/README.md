# strapi/plugin-graphql

GraphQL API on webonyx/graphql-php (port of @strapi/plugin-graphql server)

| | |
| --- | --- |
| Upstream | [`@strapi/plugin-graphql`](https://github.com/strapi/strapi/tree/develop/packages/plugins/graphql) |
| Namespace | `Strapi\Plugin\Graphql\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules. The package is a plugin (`composer.json`
`extra.strapi.kind = plugin`, `name = graphql`) enabled as soon as it is installed; a project's
`config/plugins.php` entry (`'graphql' => ['config' => [...]]`) configures it.

webonyx/graphql-php is the GraphQL engine (parser, validation, execution, type system,
introspection). What upstream gets from `nexus`, `@apollo/server`, `@as-integrations/koa`,
`@graphql-tools/*`, `graphql-scalars`, `graphql-depth-limit`, `@koa/cors` and `koa-bodyparser`
is ported under `server/src/lib/` (see "PHP-port additions").

## Port status

### Ported

| Area | Files |
| --- | --- |
| Module | `index` (`config`, `bootstrap`, `services`, plus `destroy`), `bootstrap` (`getOperationLimitsWarning`, `determineLandingPage`, the root-query-args plugin, the Apollo server, the `/graphql` route: CORS, body parser, auth, Apollo), `config/{index,default-config}` (`STRAPI_GRAPHQL_V4_COMPATIBILITY_MODE`), `format-graphql-error` |
| Services | `index` (registry), `constants`, `type-registry`, `extension/{extension,shadow-crud-manager}`, `format/{index,return-types}`, `utils/{index,naming,attributes,playground}`, `utils/mappers/{index,strapi-scalar-to-graphql-scalar,graphql-filters-to-strapi-query,graphql-scalar-to-operators,entity-to-response-entity}` |
| Content API | `content-api/{index,policy,wrap-resolvers}`, `content-api/register-functions/{collection-type,single-type,component,dynamic-zones,enums,filters,inputs,internals,polymorphic,scalars}` |
| Builders | `builders/{index,utils,type,entity,enums,input,dynamic-zones,generic-morph,response,response-collection,relation-response-collection}`, `builders/queries/{index,collection-type,single-type}`, `builders/mutations/{index,collection-type,single-type}`, `builders/filters/{index,content-type}`, `builders/filters/operators/*` (all 22), `builders/resolvers/{index,association,query,component,dynamic-zone,pagination,merge-publication-args}` |
| Internals | `internals/index`, `internals/args/{index,sort,pagination,publication-status,publication-filter,has-published-version}`, `internals/helpers/{index,get-enabled-scalars}`, `internals/scalars/{index,date,time}`, `internals/types/{index,error,filters,pagination,delete-mutation-response,publication-status,publication-filter,response-collection-meta}` |

Not ported: `services/types.ts` (types only), the barrels `extension/index.ts` and
`content-api/register-functions/index.ts`.

The extension service keeps upstream's API: `shadowCRUD(uid)` (`disable()`, `disableQueries()`,
`disableMutations()`, `disableAction(s)()`, `field(name)->disable|disableInput|disableOutput|disableFilters()`, the `is*` / `has*` readers)
and `use(configuration)` with `types` (nexus definitions), `typeDefs` (SDL), `resolvers`,
`resolversConfig` (`auth`, `policies`, `middlewares` per `Type.field`) and `plugins` (nexus
plugins), or a factory `fn (array{strapi, nexus, typeRegistry}) => configuration`. The
users-permissions, i18n and upload packages extend the schema through it.

### PHP-port additions

| File | Stands in for |
| --- | --- |
| `lib/nexus/{nexus,builder}`, `lib/nexus/definitions/*`, `lib/nexus/blocks/*` | `nexus` 1.3: `objectType`, `inputObjectType`, `interfaceType`, `unionType`, `enumType`, `scalarType`, `asNexusMethod`, `extendType`, `extendInputType`, `queryField`, `mutationField`, `nonNull` / `nullable` / `list`, `arg` / `stringArg` / `intArg` / `floatArg` / `booleanArg` / `idArg`, `plugin` (`onAddOutputField`, `onAddInputField`, `onAddArg`), `makeSchema`; definition blocks with `t.field()`, the scalar shorthands, the `asNexusMethod` methods (`t.json()`, `t.dateTime()`...), `t.implements()`, `t.members()` and the `t.nonNull` / `t.nullable` / `t.list` chains (nexus' wrapping rules, `nonNullDefaults` off). `makeSchema` (with nexus' `Query { ok: Boolean! }` when no query type is defined) also merges SDL `typeDefs` and applies `resolvers` maps (`@graphql-tools/schema` `mergeSchemas` / `addResolversToSchema`) |
| `lib/apollo-server/{apollo-server,errors,landing-page,negotiator,koa-middleware}` | `@apollo/server` 4.13 (`executeHTTPGraphQLRequest`, batching, `runHttpQuery`, CSRF prevention, the request pipeline, APQ, error normalization, `NoIntrospection`, the default landing pages), `negotiator`'s `mediaType()`, `@as-integrations/koa` 1.1 |
| `lib/graphql-scalars/{json,date-time,date,long}` | `graphql-scalars` 1.22 `GraphQLJSON`, `GraphQLDateTime`, `GraphQLDate`, `GraphQLLong` |
| `lib/graphql-depth-limit` | `graphql-depth-limit` 1.1 (a webonyx validation rule) |
| `lib/koa-cors`, `lib/koa-bodyparser` | `@koa/cors` 5.0, `koa-bodyparser` 4.4 (`strapi::body` skips the GraphQL endpoint, as upstream) |
| `graphql-context` (`GraphqlContext`) | the resolvers' context value `{ state, koaContext }` (+ `rootQueryArgsByPath`), readable as properties or offsets |
| `services/extension/{shadow-crud-content-type,shadow-crud-field}`, `services/builders/builders-instance`, `services/builders/resolvers/queries-resolvers`, `services/builders/filters/operators/operator` | the object literals upstream returns (`shadowCRUD(uid)`, `.field(name)`, the merged builders, `buildQueriesResolvers()`, the operator shape) |

### Deviations

| Upstream | Here |
| --- | --- |
| nexus `makeSchema` → `mergeSchemas({ typeDefs })` → `addResolversToSchema` → `makeSchema({ mergeSchema, plugins })` | one `Nexus::makeSchema()` over the nexus types, the SDL type definitions (a type that already exists is extended), the resolvers and the plugins; plugin hooks see every field once, SDL fields included (`type` is the declared type: a name or a wrapper) |
| `@graphql-tools/utils` `pruneSchema` | the types reachable from the root types (plus object types implementing a reachable interface); empty `Mutation` / `Subscription` types are dropped |
| nexus artifacts (`generateArtifacts`, `artifacts.schema`, `artifacts.typegen`) | the SDL file is written (nexus' header, webonyx's printer, sorted); TypeScript typegen has no PHP counterpart |
| Apollo plugins | `serverWillStart` → `renderLandingPage`, `requestDidStart` → `executionDidStart` → `willResolveField` are called; the other hooks are not. `@defer` / `@stream` (incremental delivery), gateways, usage reporting, `documentStore` and `cache` have no counterpart |
| file uploads | as upstream Strapi 5: no `Upload` scalar, and Apollo 4 rejects `multipart/form-data` requests (CSRF prevention, then "POST body missing...") |
| GraphQL errors | webonyx builds parse / validation / coercion errors (same messages as graphql-js for the cases the API suite checks); a custom scalar's `parseValue` that throws a non-GraphQL error is reported as `Expected type "X".` without the inner message. `extensions.error.name` of a non-Strapi PHP exception is its short class name (`Error` for `Exception`, `RuntimeException`, `LogicException`); `extensions.stacktrace` (non-production, non-test `NODE_ENV`) lists the PHP trace |
| JS `Date` values (`DateTime`, `Date` scalars) | RFC 3339 strings (`DateTime`: UTC with milliseconds, what `Date.toISOString()` gives; `Date`: `YYYY-MM-DD`); `DateTime` also serializes the database's `YYYY-MM-DD HH:mm:ss` strings |
| `BigInt` (`Long` scalar) | PHP ints, numeric strings beyond `PHP_INT_MAX`; values outside the JS safe-integer range serialize as strings, as an unpatched `BigInt` does |
| `JSON` scalar | `{}` and `[]` are both PHP `[]` and serialize as `[]` |
| resolvers `(parent, args, context, info)` | `info` is webonyx's `ResolveInfo` (`path` is a list; the root field's key is `path[0]`) |
| `strapi.plugin('graphql').destroy = () => server.stop()` set by `bootstrap` | the module's `destroy` lifecycle stops the server |
| `isEmpty(schema)` check in `bootstrap` | not ported (a built schema is never empty) |
| filter operators `and`, `or`, `null` | classes `AndOperator`, `OrOperator`, `NullOperator` (reserved words) |

### Tests

`tests/` ports `__tests__` (PHPUnit) on a booted `examples/getstarted` app (`tests/BootedApp.php`):
`BootstrapTest` (`bootstrap.test.ts`; upstream mocks `ApolloServer`, here `bootstrap()` runs with
a recording logger), `Services/Builders/Resolvers/MergePublicationArgsTest`. `GraphqlEndpointTest`
(not upstream) covers the endpoint's Apollo behaviour (landing page, CSRF prevention, GET
mutations, request validation, parse / validation / coercion / Strapi error formats), the depth
limit rule and the generated type names.

Upstream's API suite (`tests/api/plugins/graphql`, see `tests/api/README.md`): 119 of 123 pass,
plus the GraphQL files of the other packages (`plugins/users-permissions/{graphql,users-graphql}`,
`plugins/i18n/graphql`, `core/upload/content-api/graphql`: all pass, 4 skipped upstream).
Failing:

- `cors.test.api.js` (5 tests) requires `packages/core/strapi/dist/index.js` (an in-process
  Node Strapi) and does not load in the harness;
- `custom-resolver-dp-relations.test.api.js` (3 tests) registers JavaScript resolver functions
  through the bridge, which cannot send functions to the PHP worker.

The harness runs the suite with `STRAPI_GRAPHQL_V4_COMPATIBILITY_MODE=true`, as upstream's runner
(`tests/scripts/run-api-tests.js`) does.
