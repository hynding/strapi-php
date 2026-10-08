# strapi/provider-upload-cloudinary

Cloudinary upload provider (port of @strapi/provider-upload-cloudinary)

| | |
| --- | --- |
| Upstream | [`@strapi/provider-upload-cloudinary`](https://github.com/strapi/strapi/tree/develop/packages/providers/upload-cloudinary) |
| Namespace | `Strapi\Provider\UploadCloudinary\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

## Port status

| Upstream | PHP |
| --- | --- |
| `src/index.ts` | `src/index.php` — `UploadCloudinary::init(array $providerOptions, ?Strapi $strapi)` returns the provider instance: `uploadStream`, `upload`, `replaceStream`, `replace`, `delete` |
| `cloudinary` (Node SDK v2) | `src/cloudinary-client.php` (`CloudinaryClient`), `src/cloudinary-error.php` (`CloudinaryError`) — PHP-port additions, no Composer dependency |
| — (upstream has no tests) | `tests/UploadCloudinaryTest.php` (provider behaviour), `tests/CloudinaryClientTest.php` (HTTP level, no network, Cloudinary's documented signature example) |

## Configuration

`providerOptions` is what upstream passes to `cloudinary.config()`:

```php
// config/plugins.php
use Strapi\Utils\EnvHelper;

return static fn (EnvHelper $env): array => [
    'upload' => [
        'config' => [
            'provider' => 'cloudinary',
            'providerOptions' => [
                'cloud_name' => $env('CLOUDINARY_NAME'),
                'api_key' => $env('CLOUDINARY_KEY'),
                'api_secret' => $env('CLOUDINARY_SECRET'),
            ],
            'actionOptions' => [
                'upload' => [],
                'uploadStream' => [],
                'delete' => [],
            ],
        ],
    ],
];
```

`CLOUDINARY_URL=cloudinary://<api_key>:<api_secret>@<cloud_name>` in the environment is read as
the SDK does; explicit options win. Other honoured config keys: `secure`, `private_cdn`,
`secure_distribution`, `upload_prefix`, `signature_algorithm` (`sha1` default, `sha256`),
`signature_version`, `timeout` (ms, default 60 000), `chunk_size`.

## Behaviour (as upstream)

- Upload options: `resource_type: 'auto'`, `public_id: <hash>`, `filename: <hash><ext>` (the
  multipart file name), `folder: <path>`, then `actionOptions` / custom config on top.
- Files under 99 000 KB (`file.size`) go through `upload_stream` (one signed
  `POST /v1_1/<cloud>/<resource_type>/upload`); larger or unsized files through
  `upload_chunked_stream` (20 000 000-byte parts with `Content-Range` and one
  `X-Unique-Upload-Id`).
- `file.url` = `secure_url`, `file.provider_metadata` = `{ public_id, resource_type }`; videos also
  get `file.previewUrl` (`…/video/upload/c_scale,dl_200,vs_6,w_250/<public_id>.gif`).
- A "File size too large" error becomes `PayloadTooLargeError`; others
  `Error uploading to cloudinary: <message>`.
- `replace` with an unchanged `public_id` overwrites in place (`overwrite`, `invalidate`); otherwise
  it uploads, then destroys the old asset. `delete` destroys with the stored `resource_type`
  (default `image`) and `invalidate: true`, accepting `ok` and `not found`; failures become
  `Error deleting on cloudinary: <message>`.

## The Cloudinary client

Requests are signed as the SDK's `api_sign_request` does: non-blank parameters sorted by name,
`k=v` pairs joined with `&` (signature version 2 escapes `&` inside a value as `%26`), API secret
appended, SHA-1 (or SHA-256) hex. `api_key` and `signature` are added after signing; `file`,
`resource_type`, `cloud_name` and `api_key` are not signed. Errors are `CloudinaryError`
(`ApplicationError` with the SDK's `message` / `http_code` / `name`).

### Not replicated from the SDK

- Upload parameters are the scalar ones of `build_upload_params` (strings as-is, booleans as 1/0,
  `tags` / `allowed_formats` arrays joined, `context` / `metadata` arrays encoded, `headers`,
  `access_control`, `regions`). `transformation`, `eager` and `responsive_breakpoints` must be
  given as their string forms: the SDK's object → transformation-string builder (and the
  incoming transformation it derives from `width` / `crop`… options) is not ported.
- `cloudinary.url()` handles simple transformation parameters (`crop`, `width`, `height`, `delay`,
  `video_sampling`, `quality`, …), the `v1` version for public IDs with folders, `secure`,
  `private_cdn` and `secure_distribution`. Not ported: chained / named transformations, layers,
  URL signatures, auth tokens, CNAMEs / CDN subdomains, and the `_a=` analytics query parameter
  the SDK appends.
- No OAuth tokens, unsigned uploads, `api_proxy` / custom agents (core's `server.proxy` applies),
  or retries. A regular upload holds the file in memory (the request body is a string), as does
  each chunk of a chunked upload.
- The upload plugin passes the Strapi instance to `init()`; without it (or without a `fetch`
  service) requests fail with an explicit error.
