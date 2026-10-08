# strapi/plugin-users-permissions

Public users, roles and OAuth providers (port of @strapi/plugin-users-permissions server)

| | |
| --- | --- |
| Upstream | [`@strapi/plugin-users-permissions`](https://github.com/strapi/strapi/tree/develop/packages/plugins/users-permissions) |
| Namespace | `Strapi\Plugin\UsersPermissions\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules. The package is a plugin (`composer.json`
`extra.strapi.kind = plugin`, `name = users-permissions`) enabled as soon as it is installed; a
project's `config/plugins.php` entry (`'users-permissions' => ['config' => [...]]`) configures it.

## Port status

### Ported

| Area | Files |
| --- | --- |
| Module | `index`, `register` (content-API strategy, `content-api.output` sanitizer, `documentation/content-api.yaml` override when the documentation plugin is present), `bootstrap/index` (grant / email / advanced settings in the plugin store, admin actions through `admin::permission`'s `actionProvider->registerMany()`, default roles and permissions, the session manager's `users-permissions` origin, the `jwtSecret` check / generation), `bootstrap/users-permissions-actions`, `config` (incl. `callback.validate`) |
| Content types | `content-types/{index,user/index,user/schema-config,role/index,permission/index}`: they replace core's fallback `plugin::users-permissions.*` schemas (`up_users`, `up_roles`, `up_permissions`, same columns and join tables) |
| Services | `jwt` (legacy-support JWTs and refresh-mode access tokens), `user`, `role`, `permission`, `users-permissions` (`getActions`, `getRoutes`, `syncPermissions`, `initialize`, `template`), `providers` (`connect`, `buildRedirectUri`), `providers-registry` (all 17 built-in providers and their `authCallback`s), `constants` |
| Controllers | `auth` (local login, register, forgot / reset / change password, email confirmation, OAuth `connect` and provider `callback`, `refresh`, `logout`, `getSessions`, `revokeSession`), `user`, `role`, `permissions`, `settings`, `content-manager-user`, `validation/{auth,email-template,user}` |
| Routes | `routes/admin/{index,role,settings,permissions}`, `routes/content-api/{index,auth,user,role,permissions,validation}` (`UsersPermissionsRouteValidator`) |
| Strategy | `strategies/users-permissions` (JWT or public role, `verify` by scope) |
| Middleware | `middlewares/rateLimit` (koa2-ratelimit through the admin package's port, `Strapi\Admin\Middlewares\RateLimit::middleware()`) |
| Utils | `utils/index`, `utils/oauth-connect/{index,oauth1,oauth2,providers}` (the grant-free OAuth 1/2 flow), `utils/provider-http`, `utils/verify-jwt-with-jwks`, `utils/refresh-cookie-options`, `utils/trim-grant-session-response`, `utils/sanitize/{sanitizers,visitors/remove-user-relation-from-role-entities}` |

Barrel files (`controllers/index`, `services/index`, `routes/index`, `content-types/index`,
`middlewares/index` are registries and ported; `utils/sanitize/index`,
`utils/sanitize/visitors/index` only re-export and are not).

### Not ported

| Upstream | Why |
| --- | --- |
| `server/src/graphql/**` | the GraphQL extension needs `strapi/plugin-graphql`, not ported yet; `register` logs a warning when a graphql plugin is installed |

### PHP-port additions

| File | Stands in for |
| --- | --- |
| `middlewares/rate-limit.php` (`Middlewares\RateLimit`) | the body of `rateLimit.js` with its exported helpers (`buildPrefixKey`, `normalizeRequestPathForRateLimit`, `buildRateLimitLoadConfig`, `ROUTES_WITHOUT_IDENTIFIER`); `rateLimit.php` must return the middleware factory |
| `utils/url-join.php` | the `url-join` npm package (4.0.1) |

### Deviations

| Upstream | Here |
| --- | --- |
| `bcryptjs` | `password_hash(PASSWORD_BCRYPT)` / `password_verify` (same `$2a$`/`$2b$`/`$2y$` hashes) |
| `jsonwebtoken` | `Strapi\Core\Utils\Jwt` (legacy mode signs with `expiresIn`, `notBefore`, `issuer`, `audience`, `subject`, `jwtid`, `algorithm`) |
| the global `fetch` in providers | core's `strapi.fetch` (`Utils\ProviderHttp::$defaultFetch`, set by `register`); tests replace `ProviderHttp::$fetch` |
| `ctx.session` (koa-session) | core's `strapi::session` state (`Strapi\Core\Middlewares\Session::STATE_KEY`); `ctx.state.session` (the current auth session `{ id }`) stays the `session` state key |
| `crypto.createPublicKey({ format: 'jwk' })` | an RSA public key built from the JWK's `n` / `e` |
| `strapi.plugin('i18n').service('sanitize')` in the role controller | used when the i18n plugin is installed (always upstream) |
| `async` service functions | synchronous; in refresh mode `jwt.issue()` returns the access token itself |

### Tests

`tests/` ports `__tests__` (PHPUnit) on a booted `examples/getstarted` app (`tests/BootedApp.php`)
instead of upstream's `global.strapi` mocks: `Controllers/AuthSessionsTest`,
`Controllers/Validation/{AuthTest,EmailTemplateTest}`, `Middlewares/RateLimitTest`,
`Services/{JwtTest,ProvidersRegistryTest}`, `Utils/{IndexTest,OauthConnectTest,RefreshCookieOptionsTest,TrimGrantSessionResponseTest}`.
Not ported: `graphql/mutations/auth/__tests__/rate-limit.test.js` (GraphQL).

Upstream's API suite (`tests/api/plugins/users-permissions`, see `tests/api/README.md`): 107 of
129 pass (4 skipped upstream); every failure is GraphQL (`graphql.test.api.js`,
`users-graphql.test.api.js`, the GraphQL cases of `email-confirmation.test.api.js`), which needs
the GraphQL plugin. `email-confirmation.test.api.js` spies on the email service
(`jest.spyOn(strapi.plugin('email').service('email'), 'send')`): the harness forwards such spies
to the PHP worker (`Strapi\ApiTests\Spy`).
