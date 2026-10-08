<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailMailgun\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailMailgun\EmailMailgun as Provider;

/** src/index.ts (no upstream unit test): the request `messages.create` sends, over a mocked fetch. */
final class IndexTest extends TestCase
{
    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var array{ok: bool, status: int, headers: array<string, string>, body: string} */
    private array $response = ['ok' => true, 'status' => 200, 'headers' => [], 'body' => '{"id":"<20260101.1@example.com>","message":"Queued. Thank you."}'];

    /** @param array<string, mixed> $providerOptions */
    private function init(array $providerOptions = ['key' => 'key-123', 'domain' => 'mg.example.com']): Provider
    {
        $requests = &$this->requests;
        $response = &$this->response;

        return Provider::init($providerOptions, ['defaultFrom' => 'noreply@example.com', 'defaultReplyTo' => 'support@example.com'], null, static function (string $url, array $options) use (&$requests, &$response): array {
            $requests[] = ['url' => $url, 'options' => $options];

            return $response;
        });
    }

    /** @return list<array{name: string, value: string, filename: string|null}> */
    private function fields(): array
    {
        $options = $this->requests[0]['options'];
        preg_match('/boundary=([^\s;]+)/', $options['headers']['Content-Type'], $m);
        $fields = [];
        foreach (explode('--' . $m[1], (string) $options['body']) as $part) {
            if (preg_match('/name="?([^";\r\n]+)"?(?:; filename="?([^";\r\n]+)"?)?.*?\r\n\r\n(.*)\r\n$/s', $part, $f) === 1) {
                $fields[] = ['name' => $f[1], 'value' => $f[3], 'filename' => $f[2] !== '' ? $f[2] : null];
            }
        }

        return $fields;
    }

    public function testRequiresKeyAndDomain(): void
    {
        try {
            Provider::init(['domain' => 'x'], [], null, static fn (): array => []);
            self::fail('expected an assertion error');
        } catch (\AssertionError $e) {
            self::assertSame('Mailgun API key is required', $e->getMessage());
        }

        $this->expectExceptionMessage('Mailgun domain is required');
        Provider::init(['key' => 'x'], [], null, static fn (): array => []);
    }

    public function testSendsTheMessageAsMultipartFormData(): void
    {
        $result = $this->init()->send([
            'to' => 'a@example.com',
            'cc' => null,
            'bcc' => '',
            'subject' => 'Hello',
            'text' => 'Plain',
            'html' => '<p>HTML</p>',
            'o:tag' => ['welcome', 'beta'],
            'o:testmode' => true,
            'h:X-Mailgun-Variables' => ['user' => 42],
            'attachment' => [['filename' => 'a.txt', 'data' => 'hello']],
        ]);

        self::assertSame('https://api.mailgun.net/v3/mg.example.com/messages', $this->requests[0]['url']);
        self::assertSame('POST', $this->requests[0]['options']['method']);
        self::assertSame('Basic ' . base64_encode('api:key-123'), $this->requests[0]['options']['headers']['Authorization']);
        self::assertSame(['status' => 200, 'id' => '<20260101.1@example.com>', 'message' => 'Queued. Thank you.'], $result);

        $byName = [];
        foreach ($this->fields() as $field) {
            $byName[$field['name']][] = $field['filename'] !== null ? [$field['filename'] => $field['value']] : $field['value'];
        }
        self::assertSame(['noreply@example.com'], $byName['from']);
        self::assertSame(['a@example.com'], $byName['to']);
        self::assertSame(['support@example.com'], $byName['h:Reply-To']);
        self::assertSame(['Hello'], $byName['subject']);
        self::assertSame(['Plain'], $byName['text']);
        self::assertSame(['<p>HTML</p>'], $byName['html']);
        self::assertSame(['welcome', 'beta'], $byName['o:tag']);
        self::assertSame(['yes'], $byName['o:testmode']);
        self::assertSame(['{"user":42}'], $byName['h:X-Mailgun-Variables']);
        self::assertSame([['a.txt' => 'hello']], $byName['attachment']);
        self::assertArrayNotHasKey('cc', $byName);
        self::assertArrayNotHasKey('bcc', $byName);
    }

    public function testUsesTheConfiguredUrlAndUsername(): void
    {
        $this->init(['key' => 'k', 'domain' => 'eu.example.com', 'url' => 'https://api.eu.mailgun.net', 'username' => 'user'])
            ->send(['from' => 'me@example.com', 'replyTo' => 'r@example.com', 'to' => 'a@b.c', 'subject' => 'S', 'text' => 'T', 'html' => 'H']);

        self::assertSame('https://api.eu.mailgun.net/v3/eu.example.com/messages', $this->requests[0]['url']);
        self::assertSame('Basic ' . base64_encode('user:k'), $this->requests[0]['options']['headers']['Authorization']);
    }

    public function testThrowsTheApiErrorMessage(): void
    {
        $this->response = ['ok' => false, 'status' => 400, 'headers' => [], 'body' => '{"message":"to parameter is not a valid address. please check documentation"}'];

        $this->expectExceptionMessage('to parameter is not a valid address. please check documentation');
        $this->expectExceptionCode(400);
        $this->init()->send(['to' => 'nope', 'subject' => 'S', 'text' => 'T', 'html' => 'H']);
    }
}
