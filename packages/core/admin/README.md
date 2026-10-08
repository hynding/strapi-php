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
| Module | `index`, `register`, `bootstrap` (default "Read Only"/"Full Access" tokens created on an empty project), `destroy`, `utils/index` (`Utils::getService`), `utils/normalize-email`, `migrations/database/migrate-prefered-language-dk-to-da` |
| Content types | all: `Permission`, `User`, `Role`, `api-token`, `api-token-permission`, `transfer-token`, `transfer-token-permission`, `session` |
| Config | `config/index`, `config/settings`, `config/email-templates/forgot-password`, `config/admin-actions`, `config/admin-conditions` |
| Users & auth | services `auth`, `user`, `passport` (+ `passport/local-strategy`), `token`, `encryption`, `metrics` (telemetry is a no-op in core), `constants`; `strategies/admin`; controllers `authentication`, `authenticated-user`, `authenticated-session`, `user`, `admin` (CE); validation `authentication/*`, `user`; `middlewares/rateLimit` (koa2-ratelimit's memory store in `middlewares/rate-limit.php`); policies `isAuthenticatedAdmin`, `isTelemetryEnabled`; `audit-logs/admin-users` |
| Roles & permissions | `domain/**`, services `permission` (+ `permission/**`), `role`, `action`, `condition`, `content-type`; controllers `permission`, `role`, `formatters/**`; validation `permission`, `role`, `action-provider`, `common-validators`, `common-functions/**`, `policies/**`; policy `hasPermissions` |
| API & admin tokens | service `api-token` (`createTokenService('content-api' \| 'admin')`: one `Services\ApiToken` class bound to a kind; registered as `api-token-content-api`, `api-token` (deprecated alias) and `api-token-admin`); controllers `api-token`, `admin-token`; strategies `content-api-token` (registered for `content-api`), `admin-token` (registered for `admin`), `api-token-utils`; validation `api-tokens`, `admin-tokens`; `audit-logs/tokens` |
| Transfer tokens | services `transfer/{index,token,permission,utils}`; controllers `transfer/{index,token}`, `transfer/runner` (push/pull WebSocket handlers from `strapi/data-transfer`, see below); `strategies/data-transfer`; `middlewares/data-transfer`; validation `transfer/token` |
| Webhooks, homepage, content API | controllers `webhooks`, `homepage`, `content-api`, `validation/schema`; service `homepage`; `audit-logs/webhooks` |
| Project settings | service `project-settings` (logos through the upload plugin: `upload.formatFileInfo`, `image-manipulation.getDimensions`, `plugin('upload').provider.uploadStream/delete`); `admin.updateProjectSettings`; validation `project-settings` |
| AI | `ai/routes/ai`, `ai/controllers/ai`, `ai/services/ai` (registered as `ai.admin`; `strapi.ai.admin` in core) |
| Routes | all: `admin`, `authentication`, `users`, `permissions`, `roles`, `webhooks`, `api-tokens`, `admin-tokens`, `content-api`, `transfer`, `homepage`, `serve-admin-panel`, `ai/routes/ai` |
| Shared | `shared/utils/session-auth`, `auth-cookie-name`, `auth-cookie-path`, `auth-cookie-domain`, `audit-log-export` (constants; their consumers are EE) |

### Stubbed / not ported yet

| Upstream | State |
| --- | --- |
| `controllers/transfer/runner` push/pull | ported: the request is authenticated (`data-transfer` strategy), verified for its scope and upgraded to the `strapi/data-transfer` push/pull handler. FPM and FrankenPHP cannot hold the WebSocket, so the upgrade only happens under `strapi transfer:serve` (route `/admin/transfer/runner/*` to it); a runner request served by the HTTP worker answers `501 Not Implemented` saying so. See packages/core/data-transfer/README.md |
| `controllers/admin.licenseTrialTimeLeft` | Enterprise license registry: throws `NotImplementedError` |
| `ai/services/ai` | ported; without an Enterprise license (never in the PHP port) `isAvailable()`/`isStrapiManagedAiEnabled()` are false, the AI routes answer 404 and the AI server is never contacted, as upstream on an unlicensed project |
