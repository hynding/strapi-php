<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendgrid\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailSendgrid\EmailSendgrid as Provider;

/** src/index.ts (upstream has no unit test): the v3 Mail Send request, over a mocked fetch. */
final class IndexTest extends TestCase
{
    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var array{ok: bool, status: int, headers: array<string, string>, body: string} */
    private array $response = ['ok' => true, 'status' => 202, 'headers' => [], 'body' => ''];

    /** @param array<string, mixed> $providerOptions */
    private function init(array $providerOptions = ['apiKey' => 'SG.key']): Provider
    {
        $requests = &$this->requests;
        $response = &$this->response;

        return Provider::init($providerOptions, ['defaultFrom' => 'Strapi <noreply@example.com>', 'defaultReplyTo' => 'support@example.com'], null, static function (string $url, array $options) use (&$requests, &$response): array {
            $requests[] = ['url' => $url, 'options' => $options];

            return $response;
        });
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode((string) $this->requests[0]['options']['body'], true);
    }

    public function testSendsTheMailSendRequest(): void
    {
        $this->init()->send([
            'to' => 'a@example.com, "Doe, John" <john@example.com>',
            'cc' => 'cc@example.com',
            'subject' => 'Hello',
            'text' => 'Plain',
            'html' => '<p>HTML</p>',
        ]);

        self::assertSame('https://api.sendgrid.com/v3/mail/send', $this->requests[0]['url']);
        self::assertSame('POST', $this->requests[0]['options']['method']);
        self::assertSame('Bearer SG.key', $this->requests[0]['options']['headers']['Authorization']);
        self::assertSame([
            'personalizations' => [[
                'to' => [['email' => 'a@example.com'], ['email' => 'john@example.com', 'name' => 'Doe, John']],
                'cc' => [['email' => 'cc@example.com']],
            ]],
            'from' => ['email' => 'noreply@example.com', 'name' => 'Strapi'],
            'reply_to' => ['email' => 'support@example.com'],
            'subject' => 'Hello',
            'content' => [['type' => 'text/plain', 'value' => 'Plain'], ['type' => 'text/html', 'value' => '<p>HTML</p>']],
        ], $this->body());
    }

    public function testPassesExtraFieldsSnakeCased(): void
    {
        $this->init(['apiKey' => 'SG.key', 'region' => 'eu'])->send([
            'from' => ['email' => 'me@example.com', 'name' => 'Me'],
            'to' => ['a@example.com'],
            'subject' => 'S',
            'text' => 'T',
            'html' => null,
            'templateId' => 'd-123',
            'dynamicTemplateData' => ['firstName' => 'Jane'],
            'customArgs' => ['campaignId' => '7'],
            'categories' => ['welcome'],
            'trackingSettings' => ['clickTracking' => ['enable' => false]],
            'attachments' => [['content' => base64_encode('hello'), 'filename' => 'a.txt', 'type' => 'text/plain', 'disposition' => 'attachment']],
        ]);

        self::assertSame('https://api.eu.sendgrid.com/v3/mail/send', $this->requests[0]['url']);
        $body = $this->body();
        self::assertSame([['to' => [['email' => 'a@example.com']], 'dynamic_template_data' => ['firstName' => 'Jane']]], $body['personalizations']);
        self::assertSame(['email' => 'me@example.com', 'name' => 'Me'], $body['from']);
        self::assertSame('d-123', $body['template_id']);
        self::assertSame(['campaignId' => '7'], $body['custom_args']);
        self::assertSame(['welcome'], $body['categories']);
        self::assertSame(['click_tracking' => ['enable' => false]], $body['tracking_settings']);
        self::assertSame([['type' => 'text/plain', 'value' => 'T']], $body['content']);
        self::assertSame([['content' => base64_encode('hello'), 'filename' => 'a.txt', 'type' => 'text/plain', 'disposition' => 'attachment']], $body['attachments']);
    }

    public function testThrowsTheApiErrors(): void
    {
        $this->response = ['ok' => false, 'status' => 401, 'headers' => [], 'body' => '{"errors":[{"message":"The provided authorization grant is invalid, expired, or revoked","field":null,"help":null}]}'];

        $this->expectExceptionMessage('The provided authorization grant is invalid, expired, or revoked');
        $this->expectExceptionCode(401);
        $this->init()->send(['to' => 'a@b.c', 'subject' => 'S', 'text' => 'T', 'html' => 'H']);
    }
}
