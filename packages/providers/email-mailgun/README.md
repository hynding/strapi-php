# strapi/provider-email-mailgun

Mailgun email provider (port of @strapi/provider-email-mailgun)

| | |
| --- | --- |
| Upstream | [`@strapi/provider-email-mailgun`](https://github.com/strapi/strapi/tree/develop/packages/providers/email-mailgun) |
| Namespace | `Strapi\Provider\EmailMailgun\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

## Port status

| Upstream | PHP |
| --- | --- |
| `src/index.ts` | `src/index.php` — `EmailMailgun::init(providerOptions, settings)` asserts `key` ("Mailgun API key is required") and `domain` ("Mailgun domain is required"), creates the client with `{ username: 'api', ...providerOptions }` and returns the provider; `send()` posts `from`, `to`, `cc`, `bcc`, `h:Reply-To`, `subject`, `text`, `html` and every extra field (`o:tag`, `v:*`, `template`, `t:variables`, `attachment`, …) |
| `src/__tests__/convert-provider-options.vitest.test.ts` | `tests/ConvertProviderOptionsTest.php`; plus `tests/IndexTest.php` (the HTTP layer is a mocked `fetch`) |

### PHP-port additions: `mailgun.js` over `strapi.fetch`

| File | Stands in for |
| --- | --- |
| `src/mailgun-client.php` (`MailgunClient`) | `new Mailgun(formData).client(options)` and `mg.messages.create(domain, data)`: `multipart/form-data` POST to `<url>/v3/<domain>/messages` (`messages.mime` when `message` is set) with HTTP Basic `username:key`. Options: `username`, `key` (both required: `Parameter "key" is required`), `url` (default `https://api.mailgun.net`; `https://api.eu.mailgun.net` for EU domains), `timeout` (ms), `headers`; `public_key` / `proxy` are ignored. As mailgun.js: falsy values are left out, arrays become repeated fields, the yes/no options (`o:testmode`, `o:tracking`, …) map booleans to `yes`/`no`, objects are JSON-encoded, `attachment` / `inline` take `{ filename, data \| content, contentType }`. Returns `{ status, id, message }`; an error throws the API message with the HTTP status as code |

`init()` takes the Strapi instance as third argument (the email plugin passes it) for
`strapi.fetch`, or a fetch callable with its signature as fourth.
