<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailMailgun;

/**
 * Port of src/index.ts.
 *
 * `init(providerOptions, settings)` checks `key` and `domain`, creates the Mailgun client with
 * `{ username: 'api', ...providerOptions }` (`url` selects the region: `https://api.mailgun.net` by
 * default, `https://api.eu.mailgun.net` for EU domains) and returns the provider instance, whose
 * `send()` calls `messages.create(domain, data)` with `h:Reply-To` and every extra field passed
 * through (`o:tag`, `v:my-var`, `template`, `attachment`, …). MailgunClient is a small HTTP client
 * over `strapi.fetch` in place of `mailgun.js`. The email plugin passes the Strapi instance as third
 * argument; a `fetch` callable (`strapi.fetch`'s signature) can be given as fourth instead.
 *
 * @phpstan-import-type Fetch from MailgunClient
 */
final class EmailMailgun
{
    private const array DEFAULT_OPTIONS = [
        'username' => 'api',
    ];

    /**
     * @param array<string, mixed> $providerOptions
     * @param array<string, mixed> $settings
     */
    private function __construct(
        public readonly MailgunClient $mg,
        private readonly array $providerOptions,
        private readonly array $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $providerOptions
     * @param array<string, mixed> $settings
     * @param Fetch|null $fetch
     */
    public static function init(array $providerOptions = [], array $settings = [], mixed $strapi = null, ?callable $fetch = null): self
    {
        if (!self::truthy($providerOptions['key'] ?? null)) {
            throw new \AssertionError('Mailgun API key is required');
        }
        if (!self::truthy($providerOptions['domain'] ?? null)) {
            throw new \AssertionError('Mailgun domain is required');
        }

        $fetch ??= self::strapiFetch($strapi);
        $mg = new MailgunClient([
            ...self::DEFAULT_OPTIONS,
            ...$providerOptions,
        ], $fetch);

        return new self($mg, $providerOptions, $settings);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function send(array $options): array
    {
        $rest = array_diff_key($options, array_flip(['from', 'to', 'cc', 'bcc', 'replyTo', 'subject', 'text', 'html']));

        $data = [
            'from' => self::truthy($options['from'] ?? null) ? $options['from'] : ($this->settings['defaultFrom'] ?? null),
            'to' => $options['to'] ?? null,
            'cc' => $options['cc'] ?? null,
            'bcc' => $options['bcc'] ?? null,
            'h:Reply-To' => self::truthy($options['replyTo'] ?? null) ? $options['replyTo'] : ($this->settings['defaultReplyTo'] ?? null),
            'subject' => $options['subject'] ?? null,
            'text' => $options['text'] ?? null,
            'html' => $options['html'] ?? null,
            ...$rest,
        ];

        return $this->mg->createMessage((string) $this->providerOptions['domain'], $data);
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

        return static fn (): never => throw new \RuntimeException('@strapi/provider-email-mailgun needs strapi.fetch: pass the Strapi instance (or a fetch callable) to init()');
    }

    private static function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0);
    }
}
