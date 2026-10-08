# strapi/email

Email service and provider abstraction (port of @strapi/email server)

| | |
| --- | --- |
| Upstream | [`@strapi/email`](https://github.com/strapi/strapi/tree/develop/packages/core/email) |
| Namespace | `Strapi\Email\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules. The package is an internal plugin (`composer.json`
`extra.strapi.kind = plugin`, `name = email`); its default provider is
[`strapi/provider-email-sendmail`](../../providers/email-sendmail).

## Port status

### Ported

| Area | Files |
| --- | --- |
| Module | `index`, `bootstrap` (provider loading, see below; the development-only sendmail migration warning; the `plugin::email.settings.read` admin action), `config` (`provider: sendmail`, `providerOptions`, `settings.defaultFrom`; no-op validator) |
| Services | `services/email`: `getProviderSettings`, `send`, `sendTemplatedEmail` |
| Controllers | `controllers/email`: `send`, `test`, `getSettings`, `verify` |
| Routes | `routes/admin` (`POST /email`, `POST /email/test`, `GET /email/settings`, `POST /email/verify`), `routes/content-api` (`POST /api/email`), `routes/validation/email` (`EmailRouteValidator`) |
| Middlewares | `middlewares/rateLimit` (`plugin::email.rateLimit`, used by the admin's `POST /admin/forgot-password`) |
| Tests | `server/src/__tests__/bootstrap.test.ts` → `tests/BootstrapTest.php`; plus `tests/Services/EmailTest.php`, `tests/Controllers/EmailTest.php`, `tests/Middlewares/RateLimitTest.php` |

Barrel files `controllers/index`, `services/index`, `routes/index`, `middlewares/index` are
registries and ported; `routes/validation/index` only re-exports and is not. `server/src/types.ts`
and `shared/types.ts` are TypeScript types only. `shared/email-address-parser.ts` (used by the admin
Settings page) is `shared/email-address-parser.php` (`EmailAddressParser::parseEmailAddress()`,
`formatEmailAddress()`, `isValidEmail()`, `parseMultipleEmailAddresses()`; `tests/Shared/`), and
`documentation/1.0.0/overrides/email-Email.json` (the documentation plugin's legacy override) is
copied as is.

### Provider loading

`bootstrap` resolves `provider: '<name>'` (lowercased) as upstream resolves
`@strapi/provider-email-<name>`: the installed Composer package `strapi/provider-email-<name>`,
then `<name>` itself — a Composer package name, a class name, or a PHP file relative to the app
(returning an object or `['init' => callable]`). A provider package names its entry class in
`composer.json` `extra.strapi.main`; `init(array $providerOptions, array $settings, Strapi $strapi)`
returns the provider instance (`send(array $options)`, and optionally `verify()`, `isIdle()`,
`close()`, `getCapabilities()`). The third argument is a PHP addition (upstream providers read
nothing ambient): the HTTP providers use it to reach `strapi.fetch`.

### Differences

| Upstream | PHP |
| --- | --- |
| `sendTemplatedEmail` compiles `subject` / `text` / `html` with `_.template(…, { interpolate })` | `<%= path %>` is replaced by the value at a data leaf path, as upstream's strict interpolation does; any other `<% … %>` / `<%- … %>` block (which lodash would evaluate as JavaScript, or reject) throws |
| `middlewares/rateLimit` requires `koa2-ratelimit` | the admin package's port of `RateLimit.middleware` (in-process memory store, shared with the admin's limiter as koa2-ratelimit's store is) |
| A provider error with `statusCode: 400` becomes an `ApplicationError` | the same for a thrown object with a public `statusCode` property equal to 400 |
| `pick(['provider', 'settings.defaultFrom', …], config)` | same paths; an empty pick serializes as `{}` |

### Stubbed / not ported yet

None.
