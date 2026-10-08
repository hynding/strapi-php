<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry\Tests\Sdk;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\Sentry\Sdk\Client;
use Strapi\Plugin\Sentry\Sdk\Dsn;
use Strapi\Plugin\Sentry\Sdk\Scope;

/** Not an upstream test: the `@sentry/node` replacement (DSN, envelope, event payload). */
final class ClientTest extends TestCase
{
    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @param array<string, mixed> $options */
    private function client(array $options = []): Client
    {
        $requests = &$this->requests;

        return Client::init([
            'dsn' => 'https://abc123@o42.ingest.sentry.io/5678',
            ...$options,
        ], static function (string $url, array $options) use (&$requests): array {
            $requests[] = ['url' => $url, 'options' => $options];

            return ['ok' => true, 'status' => 200, 'headers' => [], 'body' => '{"id":"x"}'];
        });
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function envelope(int $index = 0): array
    {
        $body = (string) $this->requests[$index]['options']['body'];
        self::assertStringEndsWith("\n", $body);
        $lines = explode("\n", rtrim($body, "\n"));
        self::assertCount(3, $lines);

        return [
            json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR),
            json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR),
            json_decode($lines[2], true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    public function testParsesDsns(): void
    {
        $dsn = Dsn::parse('https://public:secret@sentry.example.com:9000/sub/path/42');

        self::assertSame('public', $dsn->publicKey);
        self::assertSame('secret', $dsn->secretKey);
        self::assertSame('sentry.example.com', $dsn->host);
        self::assertSame('9000', $dsn->port);
        self::assertSame('sub/path', $dsn->path);
        self::assertSame('42', $dsn->projectId);
        self::assertSame('https://sentry.example.com:9000/sub/path/api/42/envelope/', $dsn->envelopeEndpoint());
        self::assertSame('https://public:secret@sentry.example.com:9000/sub/path/42', (string) $dsn);
        self::assertSame('Sentry sentry_version=7, sentry_client=x/1, sentry_key=public, sentry_secret=secret', $dsn->authHeader('x/1'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDsns(): iterable
    {
        yield 'not a url' => ['an_invalid_dsn'];
        yield 'no public key' => ['https://sentry.io/42'];
        yield 'no project' => ['https://key@sentry.io/'];
        yield 'bad protocol' => ['ftp://key@sentry.io/42'];
        yield 'non-numeric project' => ['https://key@sentry.io/abc'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDsns')]
    public function testInitThrowsOnAnInvalidDsn(string $dsn): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Client::init(['dsn' => $dsn], static fn (): array => []);
    }

    public function testPostsAnEnvelopeToTheDsnEndpoint(): void
    {
        $client = $this->client();
        $eventId = $client->captureException(new \LogicException('nope'));

        self::assertCount(1, $this->requests);
        ['url' => $url, 'options' => $options] = $this->requests[0];
        self::assertSame('https://o42.ingest.sentry.io/api/5678/envelope/', $url);
        self::assertSame('POST', $options['method']);
        self::assertSame('application/x-sentry-envelope', $options['headers']['Content-Type']);
        self::assertSame(
            'Sentry sentry_version=7, sentry_client=' . Client::SDK_NAME . '/' . Client::sdkVersion() . ', sentry_key=abc123',
            $options['headers']['X-Sentry-Auth'],
        );

        [$header, $itemHeader, $event] = $this->envelope();
        self::assertSame($eventId, $header['event_id']);
        self::assertSame($eventId, $event['event_id']);
        self::assertSame('https://abc123@o42.ingest.sentry.io/5678', $header['dsn']);
        $sentAt = $header['sent_at'];
        if (!is_string($sentAt)) {
            self::fail('sent_at must be a string');
        }
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $sentAt);
        self::assertSame('event', $itemHeader['type']);
        self::assertSame(strlen(explode("\n", (string) $options['body'])[2]), $itemHeader['length']);
        if ($eventId === null) {
            self::fail('the event was not sent');
        }
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $eventId);
    }

    public function testTheEventCarriesTheExceptionAndItsStacktrace(): void
    {
        $client = $this->client(['environment' => 'production', 'release' => 'app@2', 'serverName' => 'web-1']);
        $line = __LINE__ + 1;
        $previous = new \InvalidArgumentException('root cause');
        $client->captureException(new \RuntimeException('outer', 0, $previous));

        $event = $this->envelope()[2];
        self::assertSame('php', $event['platform']);
        self::assertSame('error', $event['level']);
        self::assertSame('production', $event['environment']);
        self::assertSame('app@2', $event['release']);
        self::assertSame('web-1', $event['server_name']);
        self::assertSame(Client::SDK_NAME, $event['sdk']['name']);
        self::assertSame(Client::sdkVersion(), $event['sdk']['version']);
        self::assertSame('php', $event['contexts']['runtime']['name']);

        $values = $event['exception']['values'];
        self::assertSame([\InvalidArgumentException::class, \RuntimeException::class], array_column($values, 'type'));
        self::assertSame(['root cause', 'outer'], array_column($values, 'value'));
        self::assertSame(['type' => 'generic', 'handled' => true], $values[1]['mechanism']);

        // oldest call first; the last frame is where the exception was created
        $frames = $values[0]['stacktrace']['frames'];
        $last = $frames[count($frames) - 1];
        self::assertSame(__FILE__, $last['abs_path']);
        self::assertSame($line, $last['lineno']);
        self::assertSame(__CLASS__ . '::' . __FUNCTION__, $last['function']);
        self::assertTrue($last['in_app']);
        self::assertStringContainsString("new \\InvalidArgumentException('root cause')", $last['context_line']);
        self::assertCount(5, $last['pre_context']);
        self::assertFalse($frames[0]['in_app'] && str_contains($frames[0]['abs_path'], '/vendor/'));
    }

    public function testWithScopeForksTheScopeAndConfigureScopeChangesTheCurrentOne(): void
    {
        $client = $this->client();
        $client->configureScope(static function (Scope $scope): void {
            $scope->setTag('global', 'yes');
            $scope->setUser(['id' => 1]);
        });

        $client->withScope(static function (Scope $scope) use ($client): void {
            $scope->setTag('local', 'only here');
            $scope->setLevel('warning');
            $scope->setFingerprint(['custom']);
            $scope->addEventProcessor(static fn (array $event): array => [...$event, 'transaction' => 'GET /x']);
            $client->captureException(new \Exception('scoped'));
        });
        $client->captureMessage('plain');

        $scoped = $this->envelope(0)[2];
        self::assertSame(['global' => 'yes', 'local' => 'only here'], $scoped['tags']);
        self::assertSame(['id' => 1], $scoped['user']);
        self::assertSame('warning', $scoped['level']);
        self::assertSame(['custom'], $scoped['fingerprint']);
        self::assertSame('GET /x', $scoped['transaction']);

        $plain = $this->envelope(1)[2];
        self::assertSame(['global' => 'yes'], $plain['tags']);
        self::assertSame('info', $plain['level']);
        self::assertSame(['formatted' => 'plain'], $plain['message']);
        self::assertArrayNotHasKey('transaction', $plain);
    }

    public function testBeforeSendAndEventProcessorsCanDropEvents(): void
    {
        $client = $this->client(['beforeSend' => static fn (array $event): ?array => $event['exception']['values'][0]['value'] === 'drop' ? null : $event]);

        self::assertNull($client->captureException(new \Exception('drop')));
        self::assertNotNull($client->captureException(new \Exception('keep')));
        $client->withScope(static function (Scope $scope) use ($client): void {
            $scope->addEventProcessor(static fn (): ?array => null);
            self::assertNull($client->captureException(new \Exception('processed away')));
        });

        self::assertCount(1, $this->requests);
    }

    public function testDisabledOrClosedClientsSendNothing(): void
    {
        $this->client(['enabled' => false])->captureException(new \Exception('x'));
        $this->client(['sampleRate' => 0])->captureException(new \Exception('x'));
        $closed = $this->client();
        $closed->close();
        $closed->captureException(new \Exception('x'));

        self::assertSame([], $this->requests);
    }
}
