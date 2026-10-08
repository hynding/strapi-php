# strapi/admin

Admin API: users, roles, permissions, API tokens, auth (port of @strapi/admin server). The React admin stays upstream's npm bundle.

| | |
| --- | --- |
| Upstream | [`@strapi/admin`](https://github.com/strapi/strapi/tree/develop/packages/core/admin) |
| Namespace | `Strapi\Admin\\` |
| Status | `in-progress` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

## Port status

Upstream server files (`server/src/…`, `shared/…`) and their state here. `ee/` is not ported
(Enterprise licence): every `strapi.EE` branch takes the community path.

### Ported

| Area | Files |
| --- | --- |
| Module | `index`, `register`, `bootstrap`, `destroy`, `utils/index` (`Utils::getService`), `utils/normalize-email`, `migrations/database/migrate-prefered-language-dk-to-da` |
| Content types | all: `Permission`, `User`, `Role`, `api-token`, `api-token-permission`, `transfer-token`, `transfer-token-permission`, `session` |
| Config | `config/index`, `config/settings`, `config/email-templates/forgot-password`, `config/admin-actions`, `config/admin-conditions` |
| Users & auth | services `auth`, `user`, `passport` (+ `passport/local-strategy`), `token`, `encryption`, `metrics` (telemetry is a no-op in core), `constants`; `strategies/admin`; controllers `authentication`, `authenticated-user`, `authenticated-session`, `user`, `admin` (CE); validation `authentication/*`, `user`; `middlewares/rateLimit` (koa2-ratelimit's memory store in `middlewares/rate-limit.php`); policies `isAuthenticatedAdmin`, `isTelemetryEnabled`; `audit-logs/admin-users` |
| Roles & permissions | `domain/**`, services `permission` (+ `permission/**`), `role`, `action`, `condition`, `content-type`; controllers `permission`, `role`, `formatters/**`; validation `permission`, `role`, `action-provider`, `common-validators`, `common-functions/**`, `policies/**`; policy `hasPermissions` |
| Routes | `admin`, `authentication`, `users`, `permissions`, `roles`, `serve-admin-panel` |
| Shared | `shared/utils/session-auth`, `auth-cookie-name`, `auth-cookie-path`, `auth-cookie-domain` |

### Stubbed / not ported yet

| Upstream | State |
| --- | --- |
| `services/api-token` | PLACEHOLDER `Services\ApiToken`: `checkSaltIsDefined`, `countAll` ported; `create` is a logged no-op (bootstrap's default tokens are not created); `syncPermissionsFor*`, `deleteTokensForUser` are no-ops; anything else throws `NotImplementedError` |
| `services/transfer/**` | PLACEHOLDER: only `token.checkSaltIsDefined()` |
| `services/project-settings` | `getProjectSettings` ported; the upload-backed update throws `NotImplementedError` (`POST /admin/project-settings` answers 501) |
| `controllers/admin.licenseTrialTimeLeft` | Enterprise license registry: throws `NotImplementedError` |
| `strategies/admin-token`, `content-api-token`, `api-token-utils`, `data-transfer` | not registered: with no content-api strategy, core keeps the content API public (PHP-port fallback) |
| `routes/forgot-password` middleware `plugin::email.rateLimit` | omitted until `strapi/email` is ported (an unknown route middleware aborts the boot); `forgotPassword` logs the missing email plugin and still answers 204 |
| api tokens, admin tokens, transfer, webhooks, homepage, content-api controller, ai, `audit-logs/{tokens,webhooks}`, `middlewares/data-transfer`, `shared/utils/audit-log-export` | PLACEHOLDER files (routes return `[]`) |
