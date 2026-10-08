<?php

declare(strict_types=1);

namespace Strapi\Email\Tests;

use Nyholm\Psr7\ServerRequest;
use Psr\Log\AbstractLogger;
use Strapi\Core\Services\Server\Context;
use Strapi\Tests\AppTestCase;

/**
 * Boots `examples/getstarted` (the email plugin with its default sendmail provider) and swaps the
 * provider for a recording one. Loaded with `require_once` (the package's autoload-dev is not part
 * of the root autoloader).
 */
abstract class EmailTestCase extends AppTestCase
{
    protected mixed $originalProvider = null;

    protected function setUp(): void
    {
        $this->originalProvider = self::strapi()->plugin('email')->provider;
    }

    protected function tearDown(): void
    {
        self::strapi()->plugin('email')->provider = $this->originalProvider;
    }

    protected static function useProvider(object $provider): void
    {
        self::strapi()->plugin('email')->provider = $provider;
    }

    /** @param array<string, mixed>|null $body */
    protected static function ctx(string $method = 'POST', string $uri = '/email', ?array $body = null): Context
    {
        $request = new ServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return new Context($request, ['keys' => ['k1', 'k2']]);
    }

    protected static function recordLogs(): EmailRecordingLogger
    {
        $logger = new EmailRecordingLogger();
        self::strapi()->set('logger', $logger);

        return $logger;
    }
}

/** A PSR-3 logger that keeps what it is given. */
final class EmailRecordingLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message];
    }

    /** @return list<string> */
    public function messages(string $level): array
    {
        return array_values(array_map(static fn (array $r): string => $r[1], array_filter($this->records, static fn (array $r): bool => $r[0] === $level)));
    }
}

/** A provider recording what it is asked to send. */
final class RecordingEmailProvider
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public ?\Throwable $error = null;

    /** @param array<string, mixed> $options */
    public function send(array $options): void
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        $this->sent[] = $options;
    }
}
