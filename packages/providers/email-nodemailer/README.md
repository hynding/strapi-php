# strapi/provider-email-nodemailer

SMTP email provider (port of @strapi/provider-email-nodemailer)

| | |
| --- | --- |
| Upstream | [`@strapi/provider-email-nodemailer`](https://github.com/strapi/strapi/tree/develop/packages/providers/email-nodemailer) |
| Namespace | `Strapi\Provider\EmailNodemailer\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

## Port status

| Upstream | PHP |
| --- | --- |
| `src/index.ts` | `src/index.php` — `EmailNodemailer::init(providerOptions, settings)` returns the provider: `send`, `verify`, `isIdle`, `close`, `getCapabilities` (never exposes secrets; keys upstream leaves `undefined` are omitted). `send()` copies the same allow-listed message fields and always forces `disableFileAccess` / `disableUrlAccess` |
| `src/utils/email-address.ts` | `src/utils/email-address.php` — `Utils\EmailAddress::{parseEmailAddress, parseMultipleEmailAddresses, formatEmailAddress, normalizeEmail, decodeRfc2047, encodeRfc2047Base64, encodeRfc2047QuotedPrintable, extractComments, unquoteString, isValidEmail}` |
| `src/utils/index.ts` | barrel, not ported |
| `src/__tests__/{index,email-address}.vitest.test.ts` | `tests/{Index,EmailAddress}Test.php` |

### PHP-port additions: `nodemailer` on symfony/mailer

| File | Stands in for |
| --- | --- |
| `src/nodemailer/index.php` (`Nodemailer\Nodemailer::createTransport`) | `nodemailer.createTransport()`; `Nodemailer::$createTransport` replaces the factory in tests (`vi.mock('nodemailer')`) |
| `src/nodemailer/transporter.php` (`Nodemailer\Transporter`) | the Transporter: `sendMail`, `verify`, `isIdle`, `close` (synchronous) |
| `src/nodemailer/smtp-transport.php` (`Nodemailer\SmtpTransport`) | the SMTP / sendmail transports, on `EsmtpTransport` / `SendmailTransport` |
| `src/nodemailer/mail-composer.php` (`Nodemailer\MailComposer`) | `lib/mail-composer`, `addressparser` and the envelope logic, on symfony/mime |

The sendmail provider uses the same `Nodemailer` (as upstream's uses `nodemailer`).
`tests/Nodemailer/SmtpTransportTest.php` covers it (with a recording symfony transport).

How nodemailer's transport options map (`providerOptions` port 1:1):

| nodemailer | symfony/mailer |
| --- | --- |
| `host` (default `localhost`), `port` (default 465 when `secure`, else 587) | `EsmtpTransport` host / port |
| `secure: true` | implicit TLS; otherwise STARTTLS when the server offers it |
| `ignoreTLS`, `requireTLS` | `setAutoTls(false)`, `setRequireTls(true)` |
| `auth.user` / `auth.pass` | username / password (CRAM-MD5, LOGIN, PLAIN, XOAUTH2 tried in order) |
| `auth.type: 'OAuth2'` + `user` + `accessToken` | XOAUTH2 with that access token |
| `authMethod` | that authenticator only |
| `name` | EHLO name (default: the host name when it is a FQDN, else `[127.0.0.1]`) |
| `localAddress` | source IP |
| `tls.rejectUnauthorized`, `tls.servername`, `tls.ciphers`, `tls.ca` (PEM file path), `tls.minVersion` | `ssl` stream context options |
| `connectionTimeout` / `greetingTimeout` / `socketTimeout` (ms) | one socket timeout (`socketTimeout`, else the largest given) |
| `pool` | keeps the connection open between messages (`close()` ends it); without it one connection per message |
| `maxMessages` | reconnect after that many messages |
| `rateLimit` / `rateDelta` | `setMaxPerSecond` |
| `dkim` (`domainName`, `keySelector`, `privateKey`, `skipFields`, `keys`) | `DkimSigner` (a message's own `dkim` wins) |
| `service` | host / port / secure of a well-known service (Gmail, Outlook365, Hotmail/Outlook, Yahoo, iCloud, Zoho, SendGrid, Mailgun, Postmark, Mailjet, Brevo/SendinBlue, SES us-east-1, Fastmail) |
| `sendmail: true`, `path`, `args` | `SendmailTransport` (`<path> -i -t` by default) |
| `streamTransport: true` / `jsonTransport: true` | nothing is sent; the result's `message` is the MIME message / the message as JSON |

Message fields supported by `sendMail`: `from`, `sender`, `to`, `cc`, `bcc`, `replyTo`,
`inReplyTo`, `references`, `subject`, `text`, `html`, `watchHtml`, `amp`, `icalEvent` (an
alternative plus an `invite.ics` attachment), `alternatives`, `attachments` (`content` with
`encoding`, data-URI `path`, `filename`, `contentType`, `contentDisposition`, `cid`, `headers`),
`attachDataUrls`, `headers`, `priority`, `messageId`, `date`, `xMailer`, `list`, `envelope`,
`raw`, `textEncoding`, `encoding`, `normalizeHeaderKey`, `dkim`, `auth` (per-message OAuth2 access
token). `sendMail` returns `{ messageId, envelope, accepted, rejected, pending, response }`.

Not supported: `proxy`, `maxConnections` (one synchronous connection), connection URLs,
`logger` / `debug`, OAuth2 token refresh (`refreshToken` / `clientId` / `clientSecret` /
`serviceClient`), the `SES` transport, `attachments[].raw`,
and the per-message `dsn` (accepted and ignored). symfony/mime validates addresses (RFC), where
nodemailer is lenient.
