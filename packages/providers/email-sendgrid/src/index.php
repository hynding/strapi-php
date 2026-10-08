<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendgrid;

/**
 * Port of src/index.ts.
 *
 * `init(providerOptions, settings)` sets the API key (`apiKey`) and data residency (`region`:
 * `global` | `eu`) and returns the provider instance, whose `send()` builds the message upstream
 * builds (`from`, `to`, `cc`, `bcc`, `replyTo`, `subject`, `text`, `html` and every extra field:
 * `templateId`, `dynamicTemplateData`, `attachments`, `categories`, …) and sends it through
 * SendgridMail, a small HTTP client over `strapi.fetch` in place of `@sendgrid/mail`. The email
 * plugin passes the Strapi instance as third argument; a `fetch` callable (`strapi.fetch`'s
 * signature) can be given as fourth instead.
 *
 * @phpstan-import-type Fetch from SendgridMail
 */
final class EmailSendgrid
{
    /** @param array<string, mixed> $settings */
    private function __construct(public readonly SendgridMail $sendgrid, private readonly array $settings)
    {
    }

    /**
     * @param array<string, mixed> $providerOptions
     * @param array<string, mixed> $settings
     * @param Fetch|null $fetch
     */
    public static function init(array $providerOptions = [], array $settings = [], mixed $strapi = null, ?callable $fetch = null): self
    {
        $sendgrid = new SendgridMail($fetch ?? self::strapiFetch($strapi));
        $sendgrid->setApiKey(is_string($providerOptions['apiKey'] ?? null) ? $providerOptions['apiKey'] : '');

        if (is_string($providerOptions['region'] ?? null) && $providerOptions['region'] !== '') {
            $sendgrid->setDataResidency($providerOptions['region']);
        }

        return new self($sendgrid, $settings);
    }

    /** @param array<string, mixed> $options */
    public function send(array $options): void
    {
        $rest = array_diff_key($options, array_flip(['from', 'to', 'cc', 'bcc', 'replyTo', 'subject', 'text', 'html']));

        $msg = [
            'from' => self::truthy($options['from'] ?? null) ? $options['from'] : ($this->settings['defaultFrom'] ?? null),
            'to' => $options['to'] ?? null,
            'cc' => $options['cc'] ?? null,
            'bcc' => $options['bcc'] ?? null,
            'replyTo' => self::truthy($options['replyTo'] ?? null) ? $options['replyTo'] : ($this->settings['defaultReplyTo'] ?? null),
            'subject' => $options['subject'] ?? null,
            'text' => $options['text'] ?? null,
            'html' => $options['html'] ?? null,
            ...$rest,
        ];

        $this->sendgrid->send($msg);
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

        return static fn (): never => throw new \RuntimeException('@strapi/provider-email-sendgrid needs strapi.fetch: pass the Strapi instance (or a fetch callable) to init()');
    }

    private static function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0);
    }
}
