# strapi/upload

Media library and upload providers (port of @strapi/upload server)

| | |
| --- | --- |
| Upstream | [`@strapi/upload`](https://github.com/strapi/strapi/tree/develop/packages/core/upload) |
| Namespace | `Strapi\Upload\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`, `shared/<same path>.php`); see
`AGENTS.md` at the repository root for the naming rules. The package is an internal plugin
(`composer.json` `extra.strapi.kind = plugin`, `name = upload`); its default provider is
[`strapi/provider-upload-local`](../../providers/upload-local).

## Port status

### Ported

| Area | Files |
| --- | --- |
| Module | `index`, `register` (provider loading, see below; `documentation/content-api.json` is registered with the documentation plugin when present), `bootstrap` (settings / view configuration defaults, admin actions through `admin::permission`'s `actionProvider->registerMany()`, webhook events, weekly metrics cron, document-service URL signing), `config`, `constants`, `errors` (`FolderContainsUnauthorizedAssetsError`), `media-library-default-notice`, `types` (phpstan types), `graphql` (the upload types, `updateUploadFile` / `deleteUploadFile` mutations and scopes, registered through `strapi/plugin-graphql`'s extension service when that plugin is installed) |
| Content types / models | `content-types/{file,folder,index}` (they replace core's fallback `plugin::upload.*` schemas: same tables, columns and indexes), `models/ai-metadata-job` |
| Services | `upload`, `provider`, `image-manipulation`, `file` (incl. `fetchUrlToInputFile` with the SSRF block list), `folder`, `api-upload-folder`, `metrics`, `weekly-metrics`, `extensions/{index,utils}` (signing / unsigning media, richtext and blocks URLs for private providers), `ai-metadata`, `ai-metadata-jobs`, `ai-metadata-provider`, `ai-metadata-strapi-managed` |
| Controllers | `admin-file`, `admin-folder`, `admin-folder-file`, `admin-settings`, `admin-upload` (incl. `uploadFromUrls` Server-Sent Events), `content-api`, `view-configuration`, `utils/{find-entity-and-check-permissions,folders}`, `validation/admin/{ai-metadata,configureView,folder,folder-file,settings,upload,utils}`, `validation/content-api/upload` |
| Routes | `admin`, `content-api` (with `request.query` keys for strictParams), `view-configuration`, `validation/upload` (`UploadRouteValidator`) |
| Middleware | `middlewares/upload` (`GET /uploads/(.*)` static serving with byte ranges) |
| Migrations | `migrations/unsign-richtext-and-blocks-urls` |
| Utils | `utils/{index,cron,images,mime-validation}` |
| MCP | `mcp/register-upload-mcp-tools` (10 tool definitions), `handlers/{read,write,folder}-handlers`, `handlers/constants`, `schemas/{input,output}-schemas`, `sanitizers/sanitize-media`, `permissions`, `ambient-instance`, `utils`, `types` — registered on core's `strapi.ai.mcp` (exposed on `POST /mcp` when `server.mcp.enabled`); `tests/api/core/mcp/mcp-upload-rbac` passes 123/123 |
| Shared | `shared/constants` (`shared/contracts/*` are TypeScript types only) |

Barrel files (`controllers/index`, `services/index`, `routes/index`, `content-types/index` are
registries and ported; `models/index`, `mcp/index`, `mcp/handlers/index`, `mcp/schemas/index`,
`routes/validation/index` only re-export and are not).

### PHP-port additions

| File | Stands in for |
| --- | --- |
| `server/src/provider.php` (`Strapi\Upload\Provider`) | the object `register.ts`'s `createProvider` builds (`actionOptions` wrapping + `baseProvider`: `extend`, `checkFileSize`, `getSignedUrl`, `isPrivate`); `has(method)` replaces `isFunction(provider[method])` |
| `utils/gd-image.php` | `sharp` (metadata, stats, resize `fit: inside`, re-encode/rotate) on GD |
| `utils/jpeg-optimizer.php` | sharp's `optimiseCoding: true`: lossless Huffman re-coding of GD's JPEGs (and no JFIF/comment), so sizes match sharp's |
| `utils/animated-gif.php` | sharp `{ animated: true }` for GIF: frame-by-frame resize |
| `utils/file-type.php` | the `file-type` npm package (magic numbers, Office/OpenDocument zip inspection) |
| `utils/mime-types.php` | the `mime-types` npm package (2.1.35 tables) |

### Provider loading

`createProvider` resolves `@strapi/provider-upload-<name>` as the installed Composer package
`strapi/provider-upload-<name>`, whose `composer.json` names its entry class in
`extra.strapi.main` (the class has a static `init(array $providerOptions, Strapi $strapi)`
returning the provider instance). Failing that, `<name>` is tried as a Composer package name, a
class name, or a PHP file relative to the app returning `['init' => callable]`.

### Files

Upstream hands koa-body's formidable files around; here a request file is an `\ArrayObject`
(`filepath`, `originalFilename`, `mimetype`, `size`, see `Utils::toInputFile()`), and the files
being processed are `\ArrayObject`s carrying `getStream` (a closure returning a stream resource)
so that properties set by a provider (`url`, …) are seen by every holder, as with JavaScript
objects. What is persisted is the JSON-serializable part (`Utils::toPlain()`).

### Deviations

| Upstream | Here |
| --- | --- |
| sharp image processing | GD: same output dimensions, formats, names (`thumbnail_<name>`, `<breakpoint>_<name>`), hashes and size fields; pixels are not byte-identical. PNG optimization is lossless (sharp palette-quantizes). TIFF (GD cannot decode it) and animated WebP keep their dimensions but get no formats and no optimization. AVIF is reported as `heif`, as sharp does. |
| `concurrentUploadSize` batches, `Promise.all` | files are processed one after the other (PHP is synchronous) |
| `uploadFromUrls` streams Server-Sent Events | the same events, sent as one `text/event-stream` body when the last URL is done |
| `createAIMetadataJob` runs the job detached | it runs after the response (`register_shutdown_function`) |
| `strapi.ai.admin` | read from the `ai.admin` container entry (registered by the admin package); AI metadata is unavailable without it |
| sharp `cache` / `concurrency` options | ignored (no GD counterpart) |
| koa's EPIPE error filter (`middlewares/upload`) | no counterpart |

### Tests

`tests/` ports `__tests__` (PHPUnit, several on a booted `examples/getstarted` app instead of
Jest mocks): config, cron, media-library-default-notice, register, validation (ai-metadata,
focal point), services (folder, file, provider, image-manipulation, upload: formatFileInfo,
upload/replace/updateFileInfo), utils (mime-validation, file-type/mime-types), MCP (sanitize-media,
schemas, handlers). GD-dependent tests are skipped without the `gd` extension.
