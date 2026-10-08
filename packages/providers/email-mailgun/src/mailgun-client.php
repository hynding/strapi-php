<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailMailgun;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

/**
 * Not an upstream file: the part of `mailgun.js` (`new Mailgun(formData).client(options)` and
 * `mg.messages.create(domain, data)`) the provider uses, as a small HTTP client over `strapi.fetch`.
 *
 * Client options: `username` (required, the provider defaults it to `api`), `key` (required),
 * `url` (default `https://api.mailgun.net`; `https://api.eu.mailgun.net` for EU domains),
 * `timeout` (ms), `headers`; `public_key` and `proxy` are accepted and ignored.
 *
 * `createMessage()` POSTs `multipart/form-data` to `<url>/v3/<domain>/messages` (or
 * `messages.mime` when `data.message` is set) with HTTP Basic auth `username:key`. As mailgun.js
 * does, falsy values are left out, arrays become repeated fields, the yes/no options (`o:testmode`,
 * `o:tracking`, …) turn booleans into `yes`/`no`, objects are JSON-encoded, and `attachment` /
 * `inline` take `{ filename, data | content, contentType }` entries (or a list of them). The result
 * is `{ status, id, message }`; an error response throws with the API's message and status.
 *
 * @phpstan-type Fetch callable(string, array{method?: string, headers?: array<string, string>, body?: string|null, timeout?: int|float}): array{ok: bool, status: int, headers: array<string, string>, body: string}
 */
final class MailgunClient
{
    private const array YES_NO_PROPERTIES = [
        'o:testmode', 't:text', 'o:dkim', 'o:tracking', 'o:tracking-clicks', 'o:tracking-opens',
        'o:require-tls', 'o:skip-verification',
    ];

    public readonly string $username;

    public readonly string $key;

    public readonly string $url;

    /** @var array<string, string> */
    public readonly array $headers;

    private readonly float $timeout;

    /** @var Fetch */
    private $fetch;

    /**
     * @param array<string, mixed> $options
     * @param Fetch $fetch
     */
    public function __construct(array $options, callable $fetch)
    {
        if (!is_string($options['username'] ?? null) || $options['username'] === '') {
            throw new \InvalidArgumentException('Parameter "username" is required');
        }
        if (!is_string($options['key'] ?? null) || $options['key'] === '') {
            throw new \InvalidArgumentException('Parameter "key" is required');
        }

        $this->username = $options['username'];
        $this->key = $options['key'];
        $this->url = rtrim(is_string($options['url'] ?? null) && $options['url'] !== '' ? $options['url'] : 'https://api.mailgun.net', '/');
        $this->headers = is_array($options['headers'] ?? null) ? array_map('strval', $options['headers']) : [];
        $this->timeout = is_numeric($options['timeout'] ?? null) ? ((float) $options['timeout']) / 1000 : 60.0;
        $this->fetch = $fetch;
    }

    /**
     * `mg.messages.create(domain, data)`
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createMessage(string $domain, array $data): array
    {
        $path = isset($data['message']) && $data['message'] !== '' ? 'messages.mime' : 'messages';
        $form = new FormDataPart(self::fields($data));

        $headers = [...$this->headers];
        foreach ($form->getPreparedHeaders()->all() as $header) {
            $headers[$header->getName()] = $header->getBodyAsString();
        }
        $headers['Authorization'] = 'Basic ' . base64_encode("{$this->username}:{$this->key}");

        $response = ($this->fetch)("{$this->url}/v3/" . rawurlencode($domain) . "/{$path}", [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $form->bodyToString(),
            'timeout' => $this->timeout,
        ]);

        $body = json_decode($response['body'], true);
        if (!$response['ok']) {
            $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : (trim($response['body']) !== '' ? trim($response['body']) : "Request failed with status {$response['status']}");

            throw new \RuntimeException($message, $response['status']);
        }

        return ['status' => $response['status'], ...(is_array($body) ? $body : [])];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, string|DataPart>>
     */
    private static function fields(array $data): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::YES_NO_PROPERTIES, true) && is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            if ($value === null || $value === false || $value === '' || $value === 0) {
                continue;
            }

            if ($key === 'attachment' || $key === 'inline' || ($key === 'message' && !is_string($value))) {
                $files = is_array($value) && array_is_list($value) ? $value : [$value];
                foreach ($files as $file) {
                    $part = self::file($file, $key);
                    if ($part !== null) {
                        $fields[] = [$key => $part];
                    }
                }
                continue;
            }

            $values = is_array($value) && array_is_list($value) ? $value : [$value];
            foreach ($values as $item) {
                if ($item === null) {
                    continue;
                }
                $fields[] = [$key => match (true) {
                    is_string($item) => $item,
                    is_bool($item) => $item ? 'true' : 'false',
                    is_scalar($item) => (string) $item,
                    default => (string) json_encode($item),
                }];
            }
        }

        return $fields;
    }

    private static function file(mixed $file, string $key): ?DataPart
    {
        if (is_string($file)) {
            return new DataPart($file, $key === 'message' ? 'message.mime' : 'file');
        }
        if (!is_array($file)) {
            return null;
        }
        $data = $file['data'] ?? $file['content'] ?? null;
        if (is_resource($data)) {
            $data = (string) stream_get_contents($data);
        }
        if (!is_string($data)) {
            return null;
        }
        if (($file['encoding'] ?? null) === 'base64') {
            $data = (string) base64_decode($data);
        }
        $filename = is_string($file['filename'] ?? null) ? $file['filename'] : 'file';
        $contentType = is_string($file['contentType'] ?? null) ? $file['contentType'] : null;

        return new DataPart($data, $filename, $contentType);
    }
}
