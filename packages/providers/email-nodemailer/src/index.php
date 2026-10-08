<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailNodemailer;

use Strapi\Provider\EmailNodemailer\Nodemailer\Nodemailer;
use Strapi\Provider\EmailNodemailer\Nodemailer\Transporter;

/**
 * Port of src/index.ts.
 *
 * `init(providerOptions, settings)` creates the transport with `nodemailer.createTransport(
 * providerOptions)` — here Nodemailer\Nodemailer on symfony/mailer, whose SmtpTransport documents
 * how nodemailer's transport options map — and returns the provider instance: `send`, `verify`,
 * `isIdle`, `close`, `getCapabilities`. `send()` takes the same message fields as upstream
 * (`from`, `to`, `cc`, `bcc`, `replyTo`, `sender`, `subject`, `text`, `html`, `watchHtml`, `amp`,
 * `attachments`, `alternatives`, `headers`, `priority`, `messageId`, `date`, `xMailer`,
 * `inReplyTo`, `references`, `textEncoding`, `encoding`, `normalizeHeaderKey`, `icalEvent`,
 * `list`, `envelope`, `dkim`, `attachDataUrls`, `raw`, `dsn`, `auth`) and returns nodemailer's
 * SentMessageInfo (`messageId`, `envelope`, `accepted`, `rejected`, `pending`, `response`).
 *
 * @phpstan-type ProviderCapabilities array{transport?: array{host?: string, port?: int, secure?: bool, pool?: bool, maxConnections?: int}, auth?: array{type?: string, user?: string}, features?: list<string>}
 */
final class EmailNodemailer
{
    /**
     * @param array<string, mixed> $providerOptions
     * @param array<string, mixed> $settings
     */
    private function __construct(
        private readonly Transporter $transporter,
        private readonly array $providerOptions,
        private readonly array $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $providerOptions
     * @param array<string, mixed> $settings
     */
    public static function init(array $providerOptions = [], array $settings = [], mixed $strapi = null): self
    {
        $transporter = Nodemailer::createTransport($providerOptions);

        return new self($transporter, $providerOptions, $settings);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed> SentMessageInfo
     */
    public function send(array $options): array
    {
        $message = [
            'from' => $options['from'] ?? $this->settings['defaultFrom'] ?? null,
            'to' => $options['to'] ?? null,
            'cc' => $options['cc'] ?? null,
            'bcc' => $options['bcc'] ?? null,
            'replyTo' => $options['replyTo'] ?? $this->settings['defaultReplyTo'] ?? null,
            'subject' => $options['subject'] ?? null,
            'text' => $options['text'] ?? $options['html'] ?? null,
            'html' => $options['html'] ?? $options['text'] ?? null,
        ];

        // Addressing
        if (self::truthy($options['sender'] ?? null)) {
            $message['sender'] = $options['sender'];
        }

        // Content
        foreach (['attachments', 'alternatives', 'watchHtml', 'amp'] as $key) {
            if (self::truthy($options[$key] ?? null)) {
                $message[$key] = $options[$key];
            }
        }

        // Headers & metadata
        foreach (['headers', 'priority', 'messageId', 'date'] as $key) {
            if (self::truthy($options[$key] ?? null)) {
                $message[$key] = $options[$key];
            }
        }
        if (array_key_exists('xMailer', $options) && $options['xMailer'] !== null) {
            $message['xMailer'] = $options['xMailer'];
        }

        // Threading
        foreach (['inReplyTo', 'references'] as $key) {
            if (self::truthy($options[$key] ?? null)) {
                $message[$key] = $options[$key];
            }
        }

        // Encoding
        foreach (['textEncoding', 'encoding', 'normalizeHeaderKey'] as $key) {
            if (self::truthy($options[$key] ?? null)) {
                $message[$key] = $options[$key];
            }
        }

        // Advanced features
        foreach (['icalEvent', 'list', 'envelope', 'dkim', 'attachDataUrls'] as $key) {
            if (self::truthy($options[$key] ?? null)) {
                $message[$key] = $options[$key];
            }
        }

        // Raw MIME
        if (self::truthy($options['raw'] ?? null)) {
            $message['raw'] = $options['raw'];
        }

        // DSN and per-message auth (supported by nodemailer, not fully typed)
        if (self::truthy($options['dsn'] ?? null)) {
            $message['dsn'] = $options['dsn'];
        }
        if (self::truthy($options['auth'] ?? null) && is_array($options['auth'])) {
            $message['auth'] = [
                'user' => $options['auth']['user'] ?? null,
                'refreshToken' => $options['auth']['refreshToken'] ?? null,
                'accessToken' => $options['auth']['accessToken'] ?? null,
                ...(isset($options['auth']['expires']) ? ['expires' => $options['auth']['expires']] : []),
            ];
        }

        // Security: always block nodemailer from reading local files or fetching
        // URLs referenced by attachments[].path / attachments[].href. These are
        // forced on after the message is built so caller-supplied values cannot
        // re-enable file/URL access (prevents LFI/SSRF via attachment sources).
        $message['disableFileAccess'] = true;
        $message['disableUrlAccess'] = true;

        return $this->transporter->sendMail($message);
    }

    public function verify(): true
    {
        return $this->transporter->verify();
    }

    public function isIdle(): bool
    {
        return $this->transporter->isIdle();
    }

    public function close(): void
    {
        $this->transporter->close();
    }

    /** @return ProviderCapabilities */
    public function getCapabilities(): array
    {
        return self::getCapabilitiesFromOptions($this->providerOptions);
    }

    /**
     * Never exposes passwords, tokens or secrets; keys upstream leaves `undefined` are omitted.
     *
     * @param array<string, mixed> $options
     * @return ProviderCapabilities
     */
    private static function getCapabilitiesFromOptions(array $options): array
    {
        $capabilities = [];
        $features = [];

        if (self::truthy($options['host'] ?? null) || self::truthy($options['port'] ?? null)) {
            $capabilities['transport'] = array_filter([
                'host' => is_string($options['host'] ?? null) ? $options['host'] : null,
                'port' => is_int($options['port'] ?? null) ? $options['port'] : null,
                'secure' => is_bool($options['secure'] ?? null) ? $options['secure'] : null,
                'pool' => is_bool($options['pool'] ?? null) ? $options['pool'] : null,
                'maxConnections' => is_int($options['maxConnections'] ?? null) ? $options['maxConnections'] : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        if (is_array($options['auth'] ?? null)) {
            $auth = $options['auth'];
            $authType = is_string($auth['type'] ?? null) && $auth['type'] !== '' ? $auth['type'] : null;
            $capabilities['auth'] = array_filter([
                'type' => $authType ?? (self::truthy($auth['user'] ?? null) ? 'login' : null),
                'user' => is_string($auth['user'] ?? null) ? $auth['user'] : null,
            ], static fn (mixed $v): bool => $v !== null);
            if ($authType === 'OAuth2') {
                $features[] = 'oauth2';
            }
        }

        if (self::truthy($options['dkim'] ?? null)) {
            $features[] = 'dkim';
        }
        if (self::truthy($options['pool'] ?? null)) {
            $features[] = 'pool';
        }
        if (self::truthy($options['proxy'] ?? null)) {
            $features[] = 'proxy';
        }
        if (self::truthy($options['rateLimit'] ?? null)) {
            $features[] = 'rateLimiting';
        }
        if (self::truthy($options['requireTLS'] ?? null)) {
            $features[] = 'requireTLS';
        }

        if (count($features) > 0) {
            $capabilities['features'] = $features;
        }

        /** @var ProviderCapabilities $capabilities */
        return $capabilities;
    }

    /** JavaScript truthiness (`[]` and `{}` are truthy). */
    private static function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0);
    }
}
