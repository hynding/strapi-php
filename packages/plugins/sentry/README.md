# strapi/plugin-sentry

Sentry error reporting (port of @strapi/plugin-sentry server)

| | |
| --- | --- |
| Upstream | [`@strapi/plugin-sentry`](https://github.com/strapi/strapi/tree/develop/packages/plugins/sentry) |
| Namespace | `Strapi\Plugin\Sentry\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules. The package is an installed plugin (`composer.json`
`extra.strapi.kind = plugin`, `name = sentry`).

## Configuration

Same options as upstream, in `config/plugins.php`:

```php
return static fn (EnvHelper $env): array => [
    'sentry' => [
        'enabled' => true,
        'config' => [
            // Only set `dsn` in production: without it the plugin is disabled (nothing is sent)
            'dsn' => $env('SENTRY_DSN'),
            'sendMetadata' => true,
            'init' => ['release' => 'my-app@1.2.3', 'sampleRate' => 1.0],
        ],
    ],
];
```

```php
$strapi->plugin('sentry')->service('sentry')->sendError($error, static function (Scope $scope): void {
    $scope->setTag('my_custom_tag', 'Tag value');
});
$client = $strapi->plugin('sentry')->service('sentry')->getInstance(); // Sdk\Client|null
```

## Port status

### Ported

| Upstream | PHP |
| --- | --- |
| `server/src/index.ts` | `server/src/index.php` (`bootstrap`, `config`, `services`) |
| `server/src/config.ts` | `server/src/config.php` (`dsn: null`, `sendMetadata: true`, `init: []`) |
| `server/src/bootstrap.ts` | `server/src/bootstrap.php` |
| `server/src/middlewares/sentry.ts` | `server/src/middlewares/sentry.php` (a global server middleware reporting every error a request throws, with the request data and the `transaction` / `strapi_version` / `method` tags, then rethrowing it) |
| `server/src/services/index.ts`, `services/sentry.ts` | `server/src/services/index.php`, `services/sentry.php` (`init()`, `getInstance()`, `sendError(error, configureScope)`) |
| `services/__tests__/sentry.vitest.test.ts` | `tests/Services/SentryTest.php` |

### PHP-port additions: the `@sentry/node` replacement

The scaffold's `sentry/sentry` SDK dependency was dropped (it pulls an HTTP client stack).
`server/src/sdk/` is a small client instead:

| File | Stands in for |
| --- | --- |
| `sdk/client.php` (`Client`) | `Sentry.init()` and the hub API upstream code calls: `captureException`, `captureMessage`, `captureEvent`, `withScope`, `configureScope`, `getCurrentScope`, `setTag(s)`, `setExtra(s)`, `setContext`, `setUser`, `addBreadcrumb`, `lastEventId`, `flush`, `close`, and `$client->Handlers::parseRequest()` |
| `sdk/scope.php` (`Scope`) | `Scope`: tags, extra, contexts, user, level, fingerprint, transaction name, breadcrumbs, event processors |
| `sdk/dsn.php` (`Dsn`) | DSN parsing (`dsnFromString`), the envelope endpoint and the `X-Sentry-Auth` header |
| `sdk/handlers.php` (`Handlers`) | `Handlers.parseRequest` / `addRequestDataToEvent` (request `cookies`, `data`, `headers`, `method`, `query_string`, `url`; `transaction`; `ip` on demand) |

Events are posted as envelopes (`application/x-sentry-envelope`) to
`https://<host>/api/<project>/envelope/` through core's `strapi.fetch`
(`Strapi\Core\Utils\Fetch`), with: the exception chain and stack trace frames (source context
lines, `in_app` = not under `vendor/`), level, tags / extra / contexts / user / fingerprint /
breadcrumbs from the scope, `environment` (Strapi's environment unless `init.environment`),
`release` (`init.release` or `SENTRY_RELEASE`), `server_name` (`init.serverName` or the host
name), runtime/OS contexts, and `sdk: { name: "strapi-php.plugin-sentry", version }`.

Supported `init` options: `dsn`, `environment`, `release`, `dist`, `serverName`, `enabled`,
`sampleRate`, `beforeSend`, `maxBreadcrumbs`, `initialScope`, `attachStacktrace`, `debug`,
`timeout` (seconds per request, default 2) and `transport` (a `strapi.fetch`-like callable).

Without a DSN no client is created and no network I/O happens.

### Not replicated from `@sentry/node`

- Integrations: no automatic capture of uncaught exceptions / unhandled rejections / console,
  HTTP / database breadcrumbs, `LinkedErrors` options, `ContextLines` options, modules list.
- Performance monitoring (transactions, spans, `tracesSampleRate`, profiling), sessions /
  release health, cron monitors, attachments, user feedback, metrics.
- Client reports, rate-limit handling (`429` / `Retry-After`) and offline buffering: a failed
  send is logged as a warning and dropped.
- Transport is synchronous (PHP has no event loop), so `flush()` / `close()` have nothing to
  wait for; a slow Sentry endpoint delays the failing request by at most `timeout`.
- Options without an effect here (accepted and ignored): `integrations`, `defaultIntegrations`,
  `tracesSampleRate`, `tracesSampler`, `profilesSampleRate`, `autoSessionTracking`,
  `sendDefaultPii`, `normalizeDepth`, `maxValueLength`, `ignoreErrors`, `denyUrls`, ...

### Differences

- `ctx._matchedRoute` (Koa) is looked up in the server's route list from `ctx.state.route`, so
  the `transaction` tag is `METHOD /api/articles/:id` like upstream.
- Upstream reports `error instanceof Error`; PHP reports every `\Throwable`.
