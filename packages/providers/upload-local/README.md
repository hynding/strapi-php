# strapi/provider-upload-local

Local filesystem upload provider (port of @strapi/provider-upload-local)

| | |
| --- | --- |
| Upstream | [`@strapi/provider-upload-local`](https://github.com/strapi/strapi/tree/develop/packages/providers/upload-local) |
| Namespace | `Strapi\Provider\UploadLocal\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

## Port status

| Upstream | PHP |
| --- | --- |
| `src/index.ts` | `src/index.php` — `Strapi\Provider\UploadLocal\UploadLocal::init(array $providerOptions, ?Strapi $strapi)` returns the provider instance: `checkFileSize`, `uploadStream`, `upload`, `replaceStream`, `replace`, `delete` |
| `src/__tests__/upload-local.vitest.test.ts` | `tests/UploadLocalTest.php` |

The upload plugin finds the provider through `composer.json` `extra.strapi.main` (`kind:
provider`). Upstream reads the ambient `strapi.dirs.static.public`; here the plugin passes the
Strapi instance to `init()`. Files are written to `<public>/uploads/<hash><ext>` from `file.stream`
(a stream resource) or `file.buffer` (a binary string), and `file.url` is set to
`/uploads/<hash><ext>`. The deprecated `providerOptions.sizeLimit` warning goes to the PHP error log
(`process.emitWarning` upstream).
