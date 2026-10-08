# strapi/data-transfer

Export/import/transfer engine (port of @strapi/data-transfer)

| | |
| --- | --- |
| Upstream | [`@strapi/data-transfer`](https://github.com/strapi/strapi/tree/develop/packages/core/data-transfer) |
| Namespace | `Strapi\DataTransfer\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules. The CLI commands (`strapi export`, `import`, `transfer`,
plus `transfer:serve`) live in `strapi/strapi` (packages/core/strapi), the
`/admin/transfer/runner/{push,pull}` controller in `strapi/admin`.

## Compatibility

- **Archives** are byte-compatible with upstream: a tar in `tar-stream`'s layout (ustar headers,
  PAX headers for names over 100 bytes or non-ASCII), `metadata.json` (`JSON.stringify(…, null, 2)`),
  `{schemas,entities,links,configuration}/<stage>_NNNNN.jsonl`, `assets/uploads/<file>` and
  `assets/metadata/<file>.json`; gzip; then `aes-128-ecb` with a scrypt key
  (`scryptSync(key, '', 16)`, N=16384, r=8, p=1). `aes128`/`aes192`/`aes256` (CBC, key and IV from
  scrypt) are supported like upstream. Archives written by `npx @strapi/strapi export` import
  with `php bin/strapi import` and the reverse, for every compression/encryption combination
  (tests/fixtures/node-export.tar.gz.enc was written by upstream's provider). Unpacked directory
  exports (`--format dir`) have the same layout.
- **Remote transfers** speak upstream's WebSocket protocol (`/admin/transfer/runner/push|pull`,
  `init`/`bootstrap`/…/`end` commands and actions, batched `stream` steps, base64 asset chunks,
  checksum negotiation, the same error envelopes and close codes), so all four directions work:
  `npx @strapi/strapi transfer --to <php app>`, `--from <php app>`, and
  `php bin/strapi transfer --to <node app>`, `--from <node app>`.
- The engine, providers, transfer policy (admin types are refused on push), schema/version
  strategies, diagnostics, progress events and CLI messages follow upstream.

## WebSockets: `strapi transfer:serve`

FPM and FrankenPHP answer a request and move on: neither can keep the upgraded connection a
remote transfer lives on. The port therefore ships its own WebSocket stack
(`utils/websocket/*`: RFC 6455 framing, client with the HTTP upgrade handshake, and a server) and
a long-running command:

```sh
php vendor/bin/strapi transfer:serve --host 127.0.0.1 --port 1338
```

It loads the app once and serves `GET /admin/transfer/runner/{push,pull}` upgrade requests
through the app's own router, middlewares and `data-transfer` auth strategy (the controller is
`admin`'s `transfer/runner`), one transfer at a time, as a single Strapi process does. Route
those two paths to it from the reverse proxy that fronts the app, e.g. Caddy:

```
handle /admin/transfer/runner/* {
	reverse_proxy 127.0.0.1:1338
}
```

A runner request that reaches the HTTP worker instead (no upgrade available) is authenticated and
verified as upstream, then answered `501 Not Implemented` with a message pointing to
`transfer:serve` (`Strapi\DataTransfer\Strapi\Remote\Handlers\Utils::NOT_SERVED_MESSAGE`). The
outgoing side (`strapi transfer --to/--from`) needs nothing extra.

## Port status

### Ported

| Area | Files |
| --- | --- |
| Engine | `engine/index` (`Engine::createTransferEngine()`, stages, presets, transforms, schema and version strategies, `semverDiff`), `engine/errors` (+ `errors/*`), `engine/validation/provider`, `engine/validation/schemas/index` |
| Errors | `errors/base`, `errors/constants`, `errors/providers` (+ `errors/providers/*`) |
| Provider contracts | `types/providers.ts` as `types/providers/{i-provider,i-source-provider,i-destination-provider}` (optional methods are checked with `method_exists`, as upstream checks `provider.method`) |
| File providers | `file/providers/source/{index,utils}`, `file/providers/destination/{index,utils}` |
| Directory providers | `directory/providers/source/index`, `directory/providers/destination/{index,utils}` |
| Local Strapi providers | `strapi/providers/local-source/{index,entities,links,configuration,assets,estimate-asset-totals}`, `strapi/providers/local-destination/{index,assets-destination-writable}`, `strategies/restore/{index,entities,links,configuration,resolve-link-ref}` |
| Remote providers | `strapi/providers/remote-source/index`, `strapi/providers/remote-destination/index`, `strapi/providers/utils` (`createDispatcher`, `connectToWebsocket`, `trimTrailingSlash`, `wait`, `waitUntil`) |
| Remote server | `strapi/remote/constants`, `flows/{index,default}`, `handlers/{abstract,constants,utils,push,pull}` |
| Queries | `strapi/queries/{entity,link}` |
| Policy and helpers | `strapi/transfer-policy`, `strapi/utils/project-settings-logos` |
| Utils | `utils/{json,diagnostic,middleware,schema,components,providers,stream,capped-warnings,transaction,transfer-asset-chunk,transfer-websocket-json,writable-async-write}`, `utils/encryption/{encrypt,decrypt}` |

Barrel `index.ts` files and the type-only `types/**` (protocol messages, entities, engine
options) are not ported; the shapes are `@phpstan-type` aliases next to their users.

### PHP-port additions

| File | Stands in for |
| --- | --- |
| `utils/stream/writable.php`, `pass-through.php`, `event-emitter.php` | Node's `Writable`, `PassThrough` and `EventEmitter` (see *Streams* below) |
| `utils/stream/bytes.php`, `jsonl.php` | `fs` read/write streams, `zlib` gzip/gunzip (`deflate_init`/`inflate_init`), the cipher streams, `stream-json`'s JSONL parser/stringer |
| `utils/tar/{pack,parser,entry}.php` | `tar-stream`'s `pack()` and `tar`'s parser (ustar, PAX and GNU long names) |
| `utils/encryption/{scrypt,cipher}.php` | `crypto.scryptSync` (pure PHP, RFC 7914; memoised per key) and `createCipheriv`'s streaming `update`/`final` with PKCS#7 padding, byte-identical to Node |
| `utils/websocket/{connection,client,server,upgrade,unexpected-response-error}.php` | the `ws` package (see above) |
| `engine/progress.php` | the engine's `progress` object (`data` + event `stream`) |
| `strapi/queries/stream.php` | knex's `.stream()`: paged reads |
| `strapi/utils/upload-provider.php` | calls on `strapi.plugin('upload').provider` and its config |

## Differences from upstream

- **Streams are synchronous.** A readable stream is an `iterable`/`Generator`, a writable is
  `Utils\Stream\Writable` (`write`/`end`/`destroy`), a transform is a closure returning the
  items to pass on. Backpressure is implicit (a write returns when it is done), so upstream's
  backpressure/heap-growth tests have no equivalent.
- **One transaction**: the local destination runs the restore in one database transaction
  (`strapi.db.transaction`), committed on `close()`, rolled back on error.
- **The transfer server handles one connection at a time** (a second client waits), like a single
  Node process handling one transfer. Pull streams wait 50 ms before the first batch of a stage
  (`Pull::FLUSH_START_DELAY_MS`): upstream sends it a DB round trip later, and the Node client only
  listens for batches once the `start` reply has resolved.
- Upstream behaviours kept as they are: `abortTransfer()` resolves `false`; errors in the restore's
  `beforeTransfer` (including a missing assets directory) surface as `restore failed …`.
- Entity ids change on restore (entities are re-created), as upstream.

## Tests

`tests/` ports the upstream `__tests__` that do not depend on stream timing:
`utils/{capped-warnings,json,transfer-asset-chunk,transfer-websocket-json}`,
`utils/encryption/encrypt` (plus byte-for-byte vectors from upstream's own cipher and an RFC 7914
scrypt vector), `file/providers/{source,destination}` (`index`, `utils`, `cleanup`; real archives
in a temp dir, plus reading tests/fixtures/node-export.tar.gz.enc), `directory/providers/*`,
`engine/engine` (stages, presets, exclude/only matrices, events, schema and version strategies,
cleanup on failure, with the fake providers of tests/Engine/Fake), `strapi/transfer-policy`,
`strapi/remote/handlers/push-security`, `local-destination/resolve-link-ref`,
`strapi/utils/project-settings-logos` (local provider cases). PHP-only:
`Utils/Websocket/ConnectionTest` (framing, masking, close handshake) and
`Engine/LocalRoundTripTest` (export through the engine to an encrypted archive, restore over the
same app, compare content, relations, components, dynamic zone, media link, asset bytes and core
store). Tests that need an instance boot tests/fixtures/app (`BootedAppTestCase`).

The upstream API tests run against this port (tests/api): `core/data-transfer/project-settings-logos`
(through a `@strapi/data-transfer` shim that runs these providers in the worker) and
`core/admin/data-transfer-push-security` (a real WebSocket client against `transfer:serve`, which
the harness starts next to the FrankenPHP worker).
