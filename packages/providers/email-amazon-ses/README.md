# strapi/provider-email-amazon-ses

Amazon SES email provider (port of @strapi/provider-email-amazon-ses)

| | |
| --- | --- |
| Upstream | [`@strapi/provider-email-amazon-ses`](https://github.com/strapi/strapi/tree/develop/packages/providers/email-amazon-ses) |
| Namespace | `Strapi\Provider\EmailAmazonSes\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

## Port status

| Upstream | PHP |
| --- | --- |
| `src/index.ts` | `src/index.php` — `EmailAmazonSes::init(providerOptions, settings)` returns the provider; `send()` calls SES `SendEmail` |
| `src/utils.ts` | `src/utils.php` — `Utils::{getClientConfig, buildSendEmailCommandInput, regionFromEndpoint, toAddressList}`, `DEFAULT_SES_ENDPOINT`, `SES_ENDPOINT_REGION_PATTERN` (legacy node-ses `key` / `secret` / `amazon`, `credentials: { key, secret, sessionToken }`, `configurationSet`, `messageTags`) |
| `src/__tests__/{index,utils,backwards-compat}.vitest.test.ts` | `tests/{Index,Utils,BackwardsCompat}Test.php` (the HTTP layer is a mocked `fetch`) |

### PHP-port additions: `@aws-sdk/client-ses` over `strapi.fetch`

| File | Stands in for |
| --- | --- |
| `src/ses-client.php` (`SesClient`) | `SESClient` + `SendEmailCommand`: SES's `SendEmail` action of the classic Query API (`Version=2010-12-01`, the API `@aws-sdk/client-ses` calls, so the input — `Source`, `Destination`, `Message`, `ReplyToAddresses`, `ConfigurationSetName`, `Tags`, `ReturnPath`, `SourceArn`, … — is the same), POSTed to `endpoint` (default `https://email.<region>.amazonaws.com`); returns `{ MessageId, $metadata }` |
| `src/signature-v4.php` (`SignatureV4`) | `@smithy/signature-v4` (service `ses`); tested against the AWS SigV4 suite (`tests/SignatureV4Test.php`) |
| `src/ses-service-exception.php` (`SesServiceException`) | `SESServiceException`: the SES error message, `name` = error code, `metadata.httpStatusCode` |

Region: `region`, else `AWS_REGION` / `AWS_DEFAULT_REGION` (else "Region is missing").
Credentials: `credentials` (`accessKeyId`, `secretAccessKey`, `sessionToken`), else the AWS default
chain — environment (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`), shared
credentials file (`AWS_SHARED_CREDENTIALS_FILE` or `~/.aws/credentials`, profile `AWS_PROFILE` or
`default`), web identity (`AWS_WEB_IDENTITY_TOKEN_FILE` + `AWS_ROLE_ARN`, EKS IRSA, via STS
`AssumeRoleWithWebIdentity`), ECS container credentials, EC2 instance metadata (IMDSv2). Other AWS
SDK client options (`maxAttempts`, `requestHandler`, …) are accepted and ignored.

`init()` takes the Strapi instance as third argument (the email plugin passes it) for
`strapi.fetch`, or a fetch callable with its signature as fourth.
