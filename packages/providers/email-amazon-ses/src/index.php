<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailAmazonSes;

/**
 * Port of src/index.ts.
 *
 * `init(providerOptions, settings)` builds the SES client from `Utils::getClientConfig`
 * (legacy node-ses `key` / `secret` / `amazon`, or AWS SDK `region` / `endpoint` /
 * `credentials`) and returns the provider instance, whose `send()` calls SES `SendEmail` with
 * `Utils::buildSendEmailCommandInput` — through SesClient, a small HTTP client over `strapi.fetch`
 * in place of `@aws-sdk/client-ses`. The email plugin passes the Strapi instance as third
 * argument; a `fetch` callable (`strapi.fetch`'s signature) can be given as fourth instead.
 *
 * @phpstan-import-type Fetch from SesClient
 */
final class EmailAmazonSes
{
    /** @param array<string, mixed> $settings */
    private function __construct(public readonly SesClient $client, private readonly array $settings)
    {
    }

    /**
     * @param array<string, mixed> $providerOptions
     * @param array<string, mixed> $settings
     * @param Fetch|null $fetch
     */
    public static function init(array $providerOptions = [], array $settings = [], mixed $strapi = null, ?callable $fetch = null): self
    {
        $fetch ??= self::strapiFetch($strapi);
        $client = new SesClient(Utils::getClientConfig($providerOptions), $fetch);

        return new self($client, $settings);
    }

    /** @param array<string, mixed> $options */
    public function send(array $options): void
    {
        $this->client->sendEmail(Utils::buildSendEmailCommandInput($options, $this->settings));
    }

    /** @return Fetch */
    private static function strapiFetch(mixed $strapi): callable
    {
        if (is_object($strapi) && method_exists($strapi, 'get')) {
            $fetch = $strapi->get('fetch');
            if (is_callable($fetch)) {
                return $fetch;
            }
        }

        return static fn (): never => throw new \RuntimeException('@strapi/provider-email-amazon-ses needs strapi.fetch: pass the Strapi instance (or a fetch callable) to init()');
    }
}
