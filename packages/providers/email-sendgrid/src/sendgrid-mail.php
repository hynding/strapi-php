<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendgrid;

/**
 * Not an upstream file: the part of `@sendgrid/mail` (with `@sendgrid/helpers`' `Mail.toJSON()`) the
 * provider uses — `setApiKey`, `client.setDataResidency` and `send(msg)` — as a small HTTP client
 * over `strapi.fetch`.
 *
 * `send()` POSTs the v3 Mail Send body to `<base>/v3/mail/send` (`https://api.sendgrid.com`, or
 * `https://api.eu.sendgrid.com` for data residency `eu`) with `Authorization: Bearer <apiKey>`:
 * `from` / `replyTo` / `to` / `cc` / `bcc` addresses (`'Name <a@b.c>'`, `{ name, email }` or lists)
 * become `{ email, name }` objects, `to` / `cc` / `bcc` (plus `dynamicTemplateData`,
 * `substitutions`, `customArgs`, `sendAt`, `headers` of a single personalization) go into
 * `personalizations[0]` unless `personalizations` is given, `text` / `html` become `content`
 * (`text/plain` first), `attachments` keep `content` (base64), `filename`, `type`, `disposition`,
 * `contentId`, and every other camelCase key is sent snake_cased (`templateId`, `mailSettings`,
 * `trackingSettings`, `ipPoolName`, `batchId`, `asm`, `categories`, …) except the contents of
 * `headers`, `customArgs`, `sections`, `substitutions` and `dynamicTemplateData`.
 * An error response throws with the API's error messages and the status as code.
 *
 * @phpstan-type Fetch callable(string, array{method?: string, headers?: array<string, string>, body?: string|null, timeout?: int|float}): array{ok: bool, status: int, headers: array<string, string>, body: string}
 */
final class SendgridMail
{
    private const array REGION_HOST_MAP = [
        'eu' => 'https://api.eu.sendgrid.com',
        'global' => 'https://api.sendgrid.com',
    ];

    /** keys whose contents are sent as given (not snake_cased) */
    private const array VERBATIM = ['headers', 'customArgs', 'custom_args', 'sections', 'substitutions', 'dynamicTemplateData', 'dynamic_template_data'];

    private string $apiKey = '';

    private string $baseUrl = self::REGION_HOST_MAP['global'];

    /** @var Fetch */
    private $fetch;

    /** @param Fetch $fetch */
    public function __construct(callable $fetch)
    {
        $this->fetch = $fetch;
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function setDataResidency(string $region): void
    {
        if (!isset(self::REGION_HOST_MAP[$region])) {
            error_log('Region can only be "global" or "eu".');

            return;
        }
        $this->baseUrl = self::REGION_HOST_MAP[$region];
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** @param array<string, mixed> $msg */
    public function send(array $msg): void
    {
        $response = ($this->fetch)("{$this->baseUrl}/v3/mail/send", [
            'method' => 'POST',
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => (string) json_encode(self::toJSON($msg), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => 30,
        ]);

        if (!$response['ok']) {
            $body = json_decode($response['body'], true);
            $messages = [];
            foreach (is_array($body) && is_array($body['errors'] ?? null) ? $body['errors'] : [] as $error) {
                if (is_array($error) && is_string($error['message'] ?? null)) {
                    $messages[] = $error['message'];
                }
            }

            throw new \RuntimeException(
                $messages !== [] ? implode('; ', $messages) : "SendGrid request failed with status {$response['status']}",
                $response['status'],
            );
        }
    }

    /**
     * `new Mail(data).toJSON()`
     *
     * @param array<string, mixed> $msg
     * @return array<string, mixed>
     */
    public static function toJSON(array $msg): array
    {
        $json = [];

        $personalizations = $msg['personalizations'] ?? null;
        if (is_array($personalizations) && $personalizations !== []) {
            $json['personalizations'] = array_map(static fn (mixed $p): array => self::personalization(is_array($p) ? $p : []), array_values($personalizations));
        } else {
            $personalization = self::personalization(array_intersect_key($msg, array_flip(['to', 'cc', 'bcc', 'dynamicTemplateData', 'substitutions', 'customArgs', 'sendAt'])));
            unset($personalization['custom_args'], $personalization['send_at']);
            $json['personalizations'] = [$personalization];
        }

        $from = self::addresses($msg['from'] ?? null)[0] ?? null;
        if ($from !== null) {
            $json['from'] = $from;
        }
        $replyTo = self::addresses($msg['replyTo'] ?? null)[0] ?? null;
        if ($replyTo !== null) {
            $json['reply_to'] = $replyTo;
        }
        if (isset($msg['replyToList'])) {
            $json['reply_to_list'] = self::addresses($msg['replyToList']);
        }
        if (is_string($msg['subject'] ?? null)) {
            $json['subject'] = $msg['subject'];
        }

        $content = is_array($msg['content'] ?? null) ? array_values($msg['content']) : [];
        if (is_string($msg['html'] ?? null) && $msg['html'] !== '') {
            $content[] = ['type' => 'text/html', 'value' => $msg['html']];
        }
        if (is_string($msg['text'] ?? null) && $msg['text'] !== '') {
            array_unshift($content, ['type' => 'text/plain', 'value' => $msg['text']]);
        }
        if ($content !== []) {
            $json['content'] = $content;
        }

        if (is_array($msg['attachments'] ?? null) && $msg['attachments'] !== []) {
            $json['attachments'] = array_map(static function (mixed $attachment): array {
                $attachment = is_array($attachment) ? $attachment : [];

                return self::snakeCase(array_filter([
                    'content' => $attachment['content'] ?? null,
                    'filename' => $attachment['filename'] ?? null,
                    'type' => $attachment['type'] ?? null,
                    'disposition' => $attachment['disposition'] ?? null,
                    'contentId' => $attachment['contentId'] ?? $attachment['content_id'] ?? null,
                ], static fn (mixed $v): bool => $v !== null));
            }, array_values($msg['attachments']));
        }

        $handled = ['personalizations', 'from', 'replyTo', 'replyToList', 'subject', 'content', 'html', 'text', 'attachments', 'to', 'cc', 'bcc', 'dynamicTemplateData', 'substitutions', 'isMultiple', 'hideWarnings'];
        foreach (array_diff_key($msg, array_flip($handled)) as $key => $value) {
            if ($value === null) {
                continue;
            }
            $key = (string) $key;
            $json[self::snake($key)] = in_array($key, self::VERBATIM, true) || !is_array($value) ? $value : self::snakeCase($value);
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private static function personalization(array $p): array
    {
        $out = [];
        foreach (['to', 'cc', 'bcc'] as $field) {
            $list = self::addresses($p[$field] ?? null);
            if ($list !== []) {
                $out[$field] = $list;
            }
        }
        foreach (array_diff_key($p, array_flip(['to', 'cc', 'bcc'])) as $key => $value) {
            if ($value === null) {
                continue;
            }
            $key = (string) $key;
            $out[self::snake($key)] = in_array($key, self::VERBATIM, true) || !is_array($value) ? $value : self::snakeCase($value);
        }

        return $out;
    }

    /** @return list<array{email: string, name?: string}> */
    private static function addresses(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [];
        }
        if (is_array($value) && isset($value['email'])) {
            $name = is_scalar($value['name'] ?? null) ? (string) $value['name'] : '';

            return [$name !== '' ? ['email' => self::scalarString($value['email']), 'name' => $name] : ['email' => self::scalarString($value['email'])]];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                $out = [...$out, ...self::addresses($item)];
            }

            return $out;
        }
        if (!is_string($value)) {
            return [];
        }

        $out = [];
        foreach (self::split($value) as $item) {
            if (preg_match('/^(.*?)\s*<([^>]+)>\s*$/s', $item, $m) === 1) {
                $name = trim($m[1]);
                if (str_starts_with($name, '"') && str_ends_with($name, '"') && strlen($name) >= 2) {
                    $name = substr($name, 1, -1);
                }
                $out[] = $name !== '' ? ['email' => trim($m[2]), 'name' => $name] : ['email' => trim($m[2])];
            } else {
                $out[] = ['email' => $item];
            }
        }

        return $out;
    }

    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Splits a comma-separated list outside quotes and angle brackets.
     *
     * @return list<string>
     */
    private static function split(string $value): array
    {
        $parts = [];
        $current = '';
        $inQuotes = false;
        $depth = 0;
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if ($char === '"') {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $char === '<') {
                $depth++;
            } elseif (!$inQuotes && $char === '>') {
                $depth--;
            } elseif (!$inQuotes && $depth <= 0 && $char === ',') {
                if (trim($current) !== '') {
                    $parts[] = trim($current);
                }
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * @param array<string|int, mixed> $value
     * @return array<string|int, mixed>
     */
    private static function snakeCase(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $verbatim = is_string($key) && in_array($key, self::VERBATIM, true);
            $newKey = is_string($key) ? self::snake($key) : $key;
            $out[$newKey] = is_array($item) && !$verbatim ? self::snakeCase($item) : $item;
        }

        return $out;
    }

    private static function snake(string $key): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $key));
    }
}
