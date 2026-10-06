# strapi/utils

Shared utilities: errors, query-string parsing, query-param conversion, entity/query traversal,
sanitize/validate visitors, pagination, hooks, providers (port of `@strapi/utils`).

| | |
| --- | --- |
| Upstream | [`@strapi/utils`](https://github.com/strapi/strapi/tree/develop/packages/core/utils) |
| Namespace | `Strapi\Utils\` |
| Status | `foundation` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the repository
root for the naming rules. Everything is synchronous: where upstream `await`s, we call.

Schemas are accepted either as `Strapi\Types\Schema\Schema` objects or as plain `schema.json`-shaped
arrays (`['uid' => ..., 'modelType' => ..., 'attributes' => [...]]`); `ContentTypes::toArray()` /
`ContentTypes::attributes()` normalize. Every `getModel` callback is `callable(string $uid): Schema|array|null`.

## Ported files

| Upstream | PHP | Public API |
| --- | --- | --- |
| `errors.ts` | `src/errors/*.php` + `src/errors.php` | One class per file (`Errors\ApplicationError`, `ValidationError`, `YupValidationError`, `PaginationError`, `NotFoundError`, `ForbiddenError`, `UnauthorizedError`, `RateLimitError`, `PayloadTooLargeError`, `PolicyError`, `NotImplementedError`, `HttpError`), each with public `$name`, `$status`, `$details` and `toArray()`. `Errors` is the facade: `fromThrowable()`, `format()` (the `{ data: null, error }` envelope), `isApplicationError()`. |
| `env-helper.ts` | `src/env-helper.php` | `EnvHelper` — `new EnvHelper($vars)` / `EnvHelper::fromProcess()`; `$env('KEY', $default)`, `int()`, `float()`, `bool()`, `json()`, `array()`, `date()`, `oneOf()`. |
| `pagination.ts` | `src/pagination.php` | `Pagination::withDefaultPagination($args, $defaults, $maxLimit)`, `transformPagedPaginationInfo()`, `transformOffsetPaginationInfo()`. |
| `primitives/{arrays,objects,strings,dates}.ts` | `src/primitives/*.php` | `Primitives\Arrays::includesString()`; `Primitives\Objects::set/get/has/toPath/keysDeep/pick/omit/merge/isPlainObject/isEmpty`; `Primitives\Strings::nameToSlug/nameToCollectionName/toRegressedEnumValue/getCommonPath/isEqual/isCamelCase/isKebabCase/toKebabCase/kebabCase/snakeCase/camelCase/joinBy/startsWithANumber`; `Primitives\Dates::timestampCode()`. |
| `content-types.ts` | `src/content-types.php` | `ContentTypes` constants (`ID_ATTRIBUTE`, `DOC_ID_ATTRIBUTE`, `PUBLISHED_AT_ATTRIBUTE`, `CREATED_BY_ATTRIBUTE`, `UPDATED_BY_ATTRIBUTE`, `CREATED_AT_ATTRIBUTE`, `UPDATED_AT_ATTRIBUTE`, `SINGLE_TYPE`, `COLLECTION_TYPE`, `DP_PUB_STATE_LIVE/PREVIEW`, `DP_PUB_STATES`, `ID_FIELDS`, `MORPH_TO_KEYS`, `DYNAMIC_ZONE_KEYS`, `RELATION_OPERATION_KEYS`, reserved names) and every helper (`isScalarAttribute`, `isMediaAttribute`, `isRelationalAttribute`, `isComponentAttribute`, `isDynamicZoneAttribute`, `isMorphToRelationalAttribute`, `isPrivateAttribute`, `getPrivateAttributes`, `isTypedAttribute`, `isVisibleAttribute`, `get(Non)VisibleAttributes`, `get(Non)WritableAttributes`, `isWritableAttribute`, `getTimestamps`, `getCreatorFields`, `getDoesPluginOptionHaveValue`, `hasDraftAndPublish`, `isDraft`, `isSingleType`, `isCollectionType`, `isKind`, `getOptions`, `getContentTypeRoutePrefix`, `isSchema`, `isComponentSchema`, `isContentTypeSchema`, `isReservedAttributeName`, `isReservedModelName`, ...). Global `api.responses.privateAttributes` is injected with `ContentTypes::setGlobalPrivateAttributes()` (upstream reads `strapi.config`). |
| `operators.ts` | `src/operators.php` | `Operators::OPERATORS`, `isOperator()`, `isOperatorOfType()`. |
| `async.ts` | `src/async.php` | `Async::pipe()`, `map()`, `reduce()` (synchronous). |
| `hooks.ts` | `src/hooks.php` + `src/hooks/*.php` | `Hooks::createHook/createAsyncSeriesHook/createAsyncSeriesWaterfallHook/createAsyncParallelHook/createAsyncBailHook()` returning `Hooks\Hook` subclasses with `register()`, `delete()`, `getHandlers()`, `call()`. `HookContext` is the mutable `{ key, value }` holder series hooks receive. |
| `provider-factory.ts` | `src/provider-factory.php` | `ProviderFactory::create($options)` — `register`, `delete`, `get`, `values`, `keys`, `has`, `size`, `clear`, `->hooks`. |
| `policy.ts` | `src/policy.php` + `src/policy/*.php` | `Policy::createPolicy()` → `Policy\PolicyDefinition`; `Policy::createPolicyContext()` → `Policy\PolicyContext`. |
| `set-creator-fields.ts` | `src/set-creator-fields.php` | `SetCreatorFields::create(['user' => ..., 'isEdition' => ...])`, `apply()`. |
| `sort-query.ts` | `src/sort-query.php` | `SortQuery::hasSort()`, `getMeaningfulSortSegments()`. |
| `parse-type.ts` | `src/parse-type.php` | `ParseType::parseType(['type', 'value', 'forceCast'])`, `isBooleanLike()`, `parseBoolean()`, `parseDate()`, `parseTime()`, `parseDateTimeOrTimestamp()`, `parseISO()`, `toNumber()`. Same error messages as upstream. |
| `template.ts` | `src/template.php` | `Template::createStrictInterpolationRegExp()`, `createLooseInterpolationRegExp()`, plus `render()`. |
| `print-value.ts` | `src/print-value.php` | `PrintValue::printValue()`. |
| `publication-filter.ts` (parsing half) | `src/publication-filter.php` | `PublicationFilter::parsePublicationFilter()`, `validatePublicationFilterQueryParam()`, and `has-published-version-param.ts`'s `parseHasPublishedVersionQueryParam()` / `hasPublishedVersionBooleanToPublicationFilterMode()`. |
| `model-cache.ts` | `src/model-cache.php` | `ModelCache::createModelCache($getModel)` → `getModel()`, `clear()`, `callable()`. |
| `relations.ts` | `src/relations.php` | `Relations::isOneToAny/isManyToAny/isAnyToOne/isAnyToMany/isPolymorphic/getRelationalFields()`, `validRelationOrderingKeys()`. |
| `content-api-constants.ts` | `src/content-api-constants.php` | `ContentApiConstants::SHARED_QUERY_PARAM_KEYS`, `ALLOWED_QUERY_PARAM_KEYS`, `RESERVED_INPUT_PARAM_KEYS`. |
| `content-api-route-params.ts` | `src/content-api-route-params.php` | `ContentApiRouteParams::getExtraQueryKeysFromRoute()`, `getExtraRootKeysFromRouteBody()`, `runValidator()`. A route-like value is `['request' => ['query' => [key => validator], 'body' => ['application/json' => ['shape' => [key => validator]]]]]` where a validator is a `callable(mixed): mixed` (throws to reject) or a `ParamValidator` (`safeParse()`), standing in for Zod. |
| — (npm `qs`) | `src/qs.php` + `src/qs/js-array.php` | `Qs::parse($query, $options)`, `Qs::parseStrapiQuery($query)` (`strictNullHandling`, `arrayLimit: 100`, `depth: 20`), `Qs::stringify($data, $options)`. Faithful port of `qs` 6.x; `tests/QsTest.php` expectations were generated with the real library. |
| `convert-query-params.ts` | `src/convert-query-params.php` + `src/query-params-transformer.php` | `ConvertQueryParams::createTransformer(['getModel' => ...])` → `QueryParamsTransformer` with `convertSortQueryParams`, `convertStartQueryParams`, `convertLimitQueryParams`, `convertPageQueryParams`, `convertPageSizeQueryParams`, `convertPopulateQueryParams`, `convertFiltersQueryParams`, `convertFieldsQueryParams`, `convertStatusParams` (alias `convertPublicationStateParams`), `transformQueryParams($uid, $params)`. |
| `traverse-entity.ts` | `src/traverse-entity.php` | `TraverseEntity::traverse($visitor, ['schema', 'getModel', 'path', 'parent', 'allowedExtraRootKeys'], $entity)` and the curried `TraverseEntity::create($visitor, $options)`. |
| `traverse/factory.ts` | `src/traverse/factory.php` (+ `path`, `parent-node`, `context`, `transform-utils`, `visitor-options`, `visitor-utils`) | `Traverse\Factory::create()->intercept()->parse()->ignore()->on()->onAttribute()->onRelation()->onMedia()->onComponent()->onDynamicZone()->traverse()`. Visitors are `callable(Traverse\VisitorOptions $o, Traverse\VisitorUtils $u): void` with `$o->key/value/attribute/schema/path/data/parent/getModel()` and `$u->set()/remove()`. |
| `traverse/query-{filters,sort,populate,fields}.ts` | `src/traverse/query-*.php` | `Traverse\QueryFilters`, `QuerySort`, `QueryPopulate`, `QueryFields` — `::traverse($visitor, $options, $value)` and curried `::create($visitor, $options)`. |
| `sanitize/index.ts` | `src/sanitize/index.php` + `src/sanitize/api-sanitizers.php` | `Sanitize::createAPISanitizers(['getModel' => ..., 'sanitizers' => [...]])` / `Sanitize::contentAPI($getModel)` → `ApiSanitizers` with `input()`, `output()`, `query()`, `filters()`, `sort()`, `fields()`, `populate()`; options `auth`, `strictParams`, `route`. |
| `sanitize/sanitizers.ts` | `src/sanitize/sanitizers.php` | `Sanitizers::defaultSanitizeOutput/Filters/Sort/Fields/Populate()`, `sanitizePasswords()`. |
| `sanitize/visitors/*.ts` | `src/sanitize/visitors/*.php` | Invokable classes `RemovePassword`, `RemovePrivate`, `RemoveDynamicZones`, `RemoveMorphToRelations`, `RemoveRestrictedFields($fields)`, `RemoveDisallowedFields($fields)`, `RemoveRestrictedRelations($auth)`, `RemoveUnrecognizedFields`, `ExpandWildcardPopulate`. |
| `validate/index.ts` | `src/validate/index.php` + `src/validate/api-validators.php` | `Validate::createAPIValidators(['getModel' => ...])` / `Validate::contentAPI($getModel)` → `ApiValidators` with `input()`, `query()`, `filters()`, `sort()`, `fields()`, `populate()` (void, throw `ValidationError` with `details.source` / `details.param`). |
| `validate/validators.ts` | `src/validate/validators.php` | `Validators::validateFilters/Sort/Fields/Populate($ctx, $value, $include)` and the `default*` variants, plus the `*_TRAVERSALS` constants. |
| `validate/utils.ts` | `src/validate/utils.php` | `Validate\Utils::throwInvalidKey()`, `asyncCurry()`. |
| `validate/visitors/*.ts` | `src/validate/visitors/*.php` | `ThrowPassword`, `ThrowPrivate`, `ThrowDynamicZones`, `ThrowMorphToRelations`, `ThrowRestrictedFields($fields)`, `ThrowDisallowedFields($fields)`, `ThrowRestrictedRelations($auth)`, `ThrowUnrecognizedFields`. |
| — | `src/auth-scope.php` | `AuthScope` — the stand-in for upstream's global `strapi.auth.verify(auth, { scope })` used by the restricted-relation visitors. Register the real verifier at boot with `AuthScope::setVerifier(callable(mixed $auth, string $scope): bool)` and the content-type list with `setRegisteredContentTypes()`. An `$auth` value exposing `ability` (callable or object with `can()`) or `verify` is consulted directly; decisions are memoized per auth object. |

## Skipped

| Upstream | Reason |
| --- | --- |
| `zod.ts`, `yup.ts`, `validators.ts`, `format-yup-error.ts`, `validation/*` (route validators, utilities) | JS validation libraries; phase 2. `YupValidationError` keeps the error shape so later validators can raise it. |
| `publication-filter.ts` → `buildPublicationFilterWhere` | Builds knex subqueries; belongs to the database package. |
| `content-api-router.ts`, `route-serialization.ts` | Belong to core (router). |
| `package-manager.ts`, `get-preferred-pm.ts`, `install-id.ts`, `user-agent.ts`, `import-default.ts`, `file.ts`, `security.ts`, `sessions.ts`, `audit-logs.ts`, `typescript/*` | Node/npm tooling, streams, CSP merging and admin session helpers; not foundation. |
| `sanitize/visitors/remove-user-relation-from-role-entities` | Does not exist in upstream 5.56.0. |

## Deviations from upstream

- `Traverse\Parent` is named `Traverse\ParentNode` (`parent` is a reserved word in PHP).
- `qs` overflow objects (arrays past `arrayLimit`) are plain int-keyed PHP arrays; `QueryPopulate::isQsArrayLimitPopulateObject()` detects them by length (> 100) and `Validators::validatePopulate()` checks that before the dot-notation branch so the same "Too many populate entries" error is raised.
- The restricted-relation visitors call `AuthScope` instead of a global `strapi` object (see table above).
- Route-level param validation uses callables / `ParamValidator` in place of Zod schemas.
- `EnvHelper::date()` returns `null` for an unparseable value (there is no "Invalid Date" in PHP).
