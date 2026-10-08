<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry\Tests;

use Psr\Log\AbstractLogger;
use Strapi\Core\Strapi;

/**
 * Upstream assigns a partial `global.strapi` (config getter + log spies). `Strapi\Core\Strapi` is
 * final, so the tests use an un-loaded `examples/getstarted` instance with the plugin config set
 * and the logger replaced by a recorder.
 *
 * Loaded with `require_once` (the package's autoload-dev is not part of the root autoloader).
 */
final class SentryTestApp
{
    public const string VALID_DSN = 'https://public@o0.ingest.sentry.io/1234';

    public const string INVALID_DSN = 'an_invalid_dsn';

    /** @var list<array{url: string, options: array<string, mixed>}> */
    public array $requests = [];

    public readonly Strapi $strapi;

    /** @var AbstractLogger&object{logs: list<array{level: string, message: string}>} */
    public readonly AbstractLogger $logger;

    /** @param array<string, mixed> $config the `plugin::sentry` config */
    public function __construct(array $config)
    {
        $this->strapi = new Strapi(['appDir' => dirname(__DIR__, 4) . '/examples/getstarted']);
        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{level: string, message: string}> */
            public array $logs = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => is_string($level) ? $level : 'unknown', 'message' => (string) $message];
            }
        };
        $this->strapi->set('logger', $this->logger);
        $this->strapi->config()->set('plugin::sentry', $config);
    }

    /** A transport recording the requests (same signature as `strapi.fetch`). */
    public function transport(int $status = 200): \Closure
    {
        $requests = &$this->requests;

        return static function (string $url, array $options) use (&$requests, $status): array {
            $requests[] = ['url' => $url, 'options' => $options];

            return ['ok' => $status < 300, 'status' => $status, 'headers' => [], 'body' => '{}'];
        };
    }

    /** @return list<string> */
    public function messages(string $level): array
    {
        return array_values(array_map(
            static fn (array $log): string => $log['message'],
            array_filter($this->logger->logs, static fn (array $log): bool => $log['level'] === $level),
        ));
    }

    /**
     * The event of a recorded envelope.
     *
     * @return array<string, mixed>
     */
    public function event(int $index = 0): array
    {
        $lines = explode("\n", (string) $this->requests[$index]['options']['body']);

        return json_decode($lines[2], true, flags: JSON_THROW_ON_ERROR);
    }
}
