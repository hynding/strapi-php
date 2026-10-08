# strapi/content-manager

Content Manager admin API (port of @strapi/content-manager server)

| | |
| --- | --- |
| Upstream | [`@strapi/content-manager`](https://github.com/strapi/strapi/tree/develop/packages/core/content-manager) |
| Namespace | `Strapi\ContentManager\\` |
| Status | `in-progress` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules. The React admin (`admin/`) is upstream's npm bundle.

## Port status

### Ported

| Area | Files |
| --- | --- |
| Module | `index`, `register`, `bootstrap`, `destroy`, `config`, `constants/index`, `utils/index` (`Utils::getService`) |
| Controllers | `collection-types`, `single-types`, `relations`, `components`, `content-types`, `uid`, `init`; `utils/clone` (class `CloneUtils`: `clone` is reserved), `utils/metadata`, `utils/document-status`; `validation/index` (class `Validation`), `validation/dimensions`, `validation/model-configuration`, `validation/relations` |
| Services | `components`, `configuration`, `content-structure`, `content-types`, `data-mapper`, `document-manager`, `document-metadata`, `field-sizes`, `metrics`, `permission`, `permission-checker`, `populate-builder`, `uid`; `utils/{store,count,draft,draft-relations,populate}`, `utils/configuration/{index,attributes,layouts,metadatas,settings}` |
| Routes, policies, middlewares | `routes/{index,admin}`, `policies/{index,hasPermissions}`, `middlewares/{index,routing}`; `validation/zod`, `validation/policies/hasPermissions` |
| Homepage | `homepage/index`, `controllers/{index,homepage}`, `routes/{index,homepage}`, `services/{index,homepage,homepage-query-utils}` (`getCountDocuments` runs upstream's knex queries as SQL on the DBAL connection) |
| MCP | `mcp/register-content-manager-mcp-tools` (returns early when MCP is disabled, see below) |

PHP shapes (see `docs/porting-feature-packages.md`):

- A content-manager model (data-mapper's `toContentManagerModel`) is an array: `Schema::toArray()`
  plus the user `config` of the schema, without the PHP-only keys core keeps there
  (`__schema__`, `__filename__`, `actions`, `lifecycles`, `indexes`, `foreignKeys`).
- `permission-checker`: `create(['userAbility' => ..., 'model' => ...])` returns a checker (the same
  class bound to an ability and a model). Upstream's shortcuts `can.read(entity, field)`,
  `cannot.update(entity)`, `requiresEntity.read()`, `sanitizedQuery.read(query)` are
  `can('read', $entity, $field)`, `cannot('update', $entity)`, `requiresEntity('read')`,
  `sanitizedQuery($query, 'read')`. As upstream, the shortcut form sanitizes the query for `read`
  and builds the permission query for the requested action.
- `populate-builder` is the `populateBuilder(uid)` factory: `getService('populate-builder')($uid)`.
- Module-level caches of `services/utils/populate` are kept per Strapi instance.
- `routes/admin` references the routing middleware as `['resolve' => Routing::class]`.
- Configurations keep upstream's JSON shape in the core store and in responses: empty
  `settings` / `metadatas` / `edit` / `list` are objects (`Store::toJsonConfiguration`).
- i18n is not ported: where upstream calls `strapi.plugin('i18n')` (locale of relations, default
  locale, non-localized attributes), the i18n plugin is used when installed, else core's
  localization service.

### Not ported / stubbed

| Upstream | State |
| --- | --- |
| `history/**`, `preview/**` | Not ported: both directories are under Strapi's Enterprise licence (their own `LICENSE` file). `register`/`bootstrap`/`destroy` skip them; their routes, controllers and services (`history-version`, `preview`, `preview-config`) are not registered, and the `history-version` model is not created |
| `mcp/**` (but `register-content-manager-mcp-tools`) | PLACEHOLDER: core's MCP service has no `registerTool()`; when `server.mcp.enabled` is on, a warning is logged and no content-type tool is registered (`derive-content-type-mcp-tools`, `handlers/*`, `schemas/*`, `sanitizers/*`, `permissions`, `utils`, `types` not ported) |
| `shared/contracts/**`, `shared/index` | TypeScript types only, nothing executable |

## Tests

PHPUnit ports of the `__tests__` live in `tests/` (`Strapi\ContentManager\Tests\...`). Upstream
mocks `global.strapi` piece by piece; `tests/StubStrapi.php` fills an un-loaded getstarted
instance with fixtures and stubs (`tests/Mock.php` records calls), or boots one on in-memory
SQLite where upstream mocks the database or the document service.

Ported: services `components`, `content-types` (as `ConfigurationTest`), `content-structure`,
`document-manager`, `document-metadata`, `field-sizes`, `metrics`, `permission`,
`permission-checker`, `uid`; utils `count`, `draft-relations`, `populate`, `query-populate`,
`validatable-fields-populate`, `configuration/{attributes,layouts,settings}`; controllers
`bulkDelete`, `content-types`, `countDraftRelations`, `relations-find-available` (+ the skipped
`relations` cases), `status-lookup` (as `Utils/DocumentStatusTest`), `utils/clone`,
`validation/{dimensions,model-configuration}`; `validation/{zod,zod-yup-compat}`; homepage
`homepage`, `homepage-query-utils`. Not ported: the `mcp/**` tests (MCP not ported) and
`history/**`, `preview/**` (Enterprise licence).

Upstream's HTTP suite (`tests/api/core/content-manager`) runs against this package; failures left
are in packages not ported yet (i18n, users-permissions, history/preview) or come from PHP not
distinguishing a JSON `[]` from `{}` in request bodies.
