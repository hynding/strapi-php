# strapi/provider-email-sendmail

Direct-SMTP ("sendmail") email provider (port of @strapi/provider-email-sendmail)

| | |
| --- | --- |
| Upstream | [`@strapi/provider-email-sendmail`](https://github.com/strapi/strapi/tree/develop/packages/providers/email-sendmail) |
| Namespace | `Strapi\Provider\EmailSendmail\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

## Port status

| Upstream | PHP |
| --- | --- |
| `src/index.ts` (+ `src/types.ts` shapes) | `src/index.php` — `EmailSendmail::init(providerOptions, settings)` returns the provider; `send()` fills `from` / `replyTo` from `settings.defaultFrom` / `defaultReplyTo` and passes every other field through to the nodemailer message |
| `src/direct-smtp.ts` | `src/direct-smtp.php` — `DirectSmtp::resolveMxHosts`, `DirectSmtp::sendDirectSmtp`: per recipient domain, each MX host (sorted by priority, then `smtpHost`) is tried in turn over plain SMTP (`ignoreTLS`, 60 s timeouts); `devPort` / `devHost` send to one local server instead |
| `src/addressing.ts` | `src/addressing.php` — `Addressing::{extractEmail, parseAddressList, getHostFromAddress, groupRecipientsByDomain, collectRecipients}` |
| `src/logger.ts` | `src/logger.php` — `Logger::createLogger(options)`; `options.logger` is an array of callables or an object (a PSR-3 logger's `warning` serves `warn`); output goes to the PHP error log otherwise |
| `__tests__/{index,direct-smtp,mx-fallback,addressing,logger}.vitest.test.ts` | `tests/{Index,DirectSmtp,MxFallback,Addressing,Logger}Test.php` |

Provider options are upstream's (`devPort`, `devHost`, `smtpPort`, `smtpHost`, `dkim: { privateKey,
keySelector }`, `rejectUnauthorized`, `silent`, `logger`). The SMTP client is nodemailer's, as
upstream: [`strapi/provider-email-nodemailer`](../email-nodemailer)'s `Nodemailer\Nodemailer`
(on symfony/mailer), which is why this package requires it. Attachment `path` / `href` sources are
always refused ("File access rejected" / "Url access rejected").

Test seams: `DirectSmtp::$resolveMx` (for `dns.resolveMx`, default `dns_get_record(DNS_MX)`),
`DirectSmtp::$hostname` (for `os.hostname`) and `Nodemailer::$createTransport`. Upstream's
`index` test talks to a minimal SMTP server on a local port; here the per-host transports record
the SMTP envelope and DATA instead of opening a socket.
