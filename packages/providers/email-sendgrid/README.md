# strapi/provider-email-sendgrid

SendGrid email provider (port of @strapi/provider-email-sendgrid)

| | |
| --- | --- |
| Upstream | [`@strapi/provider-email-sendgrid`](https://github.com/strapi/strapi/tree/develop/packages/providers/email-sendgrid) |
| Namespace | `Strapi\Provider\EmailSendgrid\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

## Port status

| Upstream | PHP |
| --- | --- |
| `src/index.ts` | `src/index.php` — `EmailSendgrid::init(providerOptions, settings)` sets `apiKey` and the data residency (`region`: `global` \| `eu`) and returns the provider; `send()` builds upstream's message (`from`, `to`, `cc`, `bcc`, `replyTo`, `subject`, `text`, `html` plus every extra field) |
| (no upstream tests) | `tests/IndexTest.php` (the HTTP layer is a mocked `fetch`) |

### PHP-port additions: `@sendgrid/mail` over `strapi.fetch`

| File | Stands in for |
| --- | --- |
| `src/sendgrid-mail.php` (`SendgridMail`) | `setApiKey`, `client.setDataResidency` (`https://api.sendgrid.com` / `https://api.eu.sendgrid.com`; an unknown region logs `Region can only be "global" or "eu".`) and `send(msg)` with `@sendgrid/helpers`' `Mail.toJSON()`: POST `/v3/mail/send` with `Authorization: Bearer <apiKey>`. Addresses (`'Name <a@b.c>'`, `{ name, email }`, lists) become `{ email, name }`; `to` / `cc` / `bcc` / `dynamicTemplateData` / `substitutions` go into one personalization unless `personalizations` is given; `text` / `html` become `content` (`text/plain` first; empty strings left out); other camelCase keys are sent snake_cased (`templateId`, `mailSettings`, `trackingSettings`, `ipPoolName`, `batchId`, `sendAt`, `customArgs`, …) except the contents of `headers`, `customArgs`, `sections`, `substitutions`, `dynamicTemplateData`. An error response throws the API's error messages with the HTTP status as code (upstream: a `ResponseError` whose message is the status text) |

`init()` takes the Strapi instance as third argument (the email plugin passes it) for
`strapi.fetch`, or a fetch callable with its signature as fourth.
