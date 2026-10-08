<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendmail;

/**
 * Port of src/index.ts (and the shapes of src/types.ts).
 *
 * `init(providerOptions, settings)` returns the provider instance; `send()` delivers directly to
 * each recipient domain's MX hosts (or `devHost:devPort`) over SMTP — see DirectSmtp. Like upstream,
 * every field besides the core ones is passed through to the nodemailer message (`attachments`,
 * `headers`, …); attachment `path` / `href` sources are always refused.
 *
 * Options passed through from Strapi `plugin::email` config `providerOptions`, historically
 * compatible with `require('sendmail')` (guileen/node-sendmail):
 *
 * @phpstan-type ProviderSendmailOptions array{
 *     logger?: array<string, callable>|object,
 *     silent?: bool,
 *     dkim?: bool|array{privateKey: string, keySelector?: string},
 *     devPort?: int|bool,
 *     devHost?: string,
 *     smtpPort?: int,
 *     smtpHost?: string|int,
 *     rejectUnauthorized?: bool,
 *     autoEHLO?: bool,
 * }
 * @phpstan-type Settings array{defaultFrom?: string, defaultReplyTo?: string}
 */
final class EmailSendmail
{
    /**
     * @param array<string, mixed> $mergedOptions
     * @param array<string, mixed> $settings
     */
    private function __construct(private readonly array $mergedOptions, private readonly array $settings)
    {
    }

    /**
     * @param array<string, mixed> $providerOptions
     * @param array<string, mixed> $settings
     */
    public static function init(array $providerOptions = [], array $settings = [], mixed $strapi = null): self
    {
        $mergedOptions = [
            'silent' => true,
            ...$providerOptions,
        ];

        return new self($mergedOptions, $settings);
    }

    /** @param array<string, mixed> $options */
    public function send(array $options): void
    {
        $core = ['from', 'to', 'cc', 'bcc', 'replyTo', 'subject', 'text', 'html'];
        $rest = array_diff_key($options, array_flip($core));

        $mail = [
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

        DirectSmtp::sendDirectSmtp($mail, $this->mergedOptions);
    }

    private static function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0);
    }
}
