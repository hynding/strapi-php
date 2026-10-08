# strapi/i18n

Internationalization: locales and localized content (port of @strapi/i18n server)

| | |
| --- | --- |
| Upstream | [`@strapi/i18n`](https://github.com/strapi/strapi/tree/develop/packages/plugins/i18n) |
| Namespace | `Strapi\Plugin\I18n\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules. The package is an internal plugin (`composer.json`
`extra.strapi.kind = plugin`, `name = i18n`, listed in core's `INTERNAL_PLUGINS`).

## Port status

### Ported

| Area | Files |
| --- | --- |
| Module | `index`, `register` (AI localization job model, `extendContentTypes`, the content-manager locale middleware, the `afterSync` permission repair), `bootstrap` (default locale, sections builder handler, actions + hooks, engine handler, locale lifecycles, document-service middleware syncing non-localized fields, audit events when `audit-logs-lifecycle` exists, AI localizations), `audit-logs`, `constants` (`iso-locales.json` copied verbatim) |
| Content types / models | `content-types/{index,locale/index}` (`locale/schema.json` copied verbatim; table `i18n_locale`), `models/ai-localization-job` |
| Services | `locales`, `iso-locales`, `content-types`, `localizations`, `metrics`, `permissions` + `permissions/{actions,conditions,engine,sections-builder}`, `sanitize/index`, `settings`, `ai-localizations`, `ai-localization-jobs`, `ai-translations`, `ai-translations-strapi-managed`, `fill-from-locale` |
| Controllers | `locales`, `iso-locales`, `content-types`, `settings`, `ai-localization-jobs`, `validate-locale-creation` |
| Routes | `admin`, `content-api`, `validation/locale` (`I18nLocaleRouteValidator`) |
| Validation / domain | `validation/{content-types,locales,settings}`, `domain/locale` |
| Utils | `utils/index` (`getService`, `getCoreStore` + typed helpers) |

Barrel files `services/index`, `controllers/index`, `routes/index`, `content-types/index`,
`models/index` are registries and ported; `routes/validation/index` only re-exports.
`shared/contracts/*` are TypeScript types only.

### Stubbed

| File | Why |
| --- | --- |
| `graphql` | `@strapi/plugin-graphql` (nexus) is not ported: `Graphql::register()` throws `NotImplementedError`; it only runs when a `graphql` plugin is installed. |

### PHP-port additions

| File | Stands in for |
| --- | --- |
| `server/src/localization-provider.php` | Upstream core calls `strapi.plugin('i18n').service('content-types' \| 'locales')` directly; strapi-php's core goes through `strapi.localization`. The plugin registers this provider on it in `register()`. |

### Differences

- `strapi.server.router.use('/content-manager/{collection,single}-types/:model', mw)` has no
  counterpart in the PHP router: the locale middleware is an admin API middleware applied to
  the same paths (POST / PUT), so it runs after the admin API's own middlewares.
- Schemas are immutable: `extendContentTypes` replaces each content type with a copy carrying
  the `locale` / `localizations` attributes (core's schema factory already adds the same ones).
- `actionProvider.values().forEach(mutate)` writes the changed actions back with the action
  provider's `replace()` (PHP arrays are copies).
- AI localizations run synchronously in the document-service middleware (upstream does not await
  them); errors are logged as upstream.
- `setDefaultLocale()` returns `null` (core-store `set()` is void).
