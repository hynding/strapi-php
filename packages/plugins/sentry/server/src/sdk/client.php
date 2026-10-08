<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry\Sdk;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Not an upstream file: the replacement for the `@sentry/node` namespace upstream's plugin wraps
 * (`Sentry.init()` + the hub API it calls). It builds error events — exception chain with
 * stack trace frames (and source context lines), level, tags / extra / contexts / user /
 * fingerprint / breadcrumbs from the scope, environment, release, server_name and an `sdk` block
 * naming this port — and posts them as envelopes to `<dsn host>/api/<project>/envelope/` with
 * the `X-Sentry-Auth` header, through a fetch-like transport (`strapi.fetch` in the plugin).
 *
 * Supported `init` options: `dsn`, `environment`, `release`, `dist`, `serverName`
 * (or `server_name`), `enabled`, `sampleRate`, `beforeSend`, `maxBreadcrumbs`, `initialScope`,
 * `attachStacktrace`, `debug`, `timeout` (seconds, per request) and `transport`
 * (`callable(string $url, array $options): mixed`, same signature as `strapi.fetch`). Other
 * `@sentry/node` options (integrations, tracing, profiling, sessions...) are accepted and ignored.
 *
 * Sending is synchronous (PHP has no event loop): a transport error is logged, never thrown.
 */
final class Client
{
    public const string SDK_NAME = 'strapi-php.plugin-sentry';

    private const int CONTEXT_LINES = 5;

    public readonly Dsn $dsn;

    public readonly Handlers $Handlers;

    /** @var list<Scope> the scope stack; the last one is the current scope */
    private array $scopes;

    private ?string $lastEventId = null;

    private bool $closed = false;

    /** @var callable(string, array<string, mixed>): mixed */
    private $transport;

    /**
     * @param array<string, mixed> $options
     * @param callable(string, array<string, mixed>): mixed $transport
     */
    private function __construct(private readonly array $options, callable $transport, private readonly LoggerInterface $logger)
    {
        $this->dsn = Dsn::parse((string) $options['dsn']);
        $this->transport = $transport;
        $this->Handlers = new Handlers();

        $scope = new Scope(is_int($options['maxBreadcrumbs'] ?? null) ? $options['maxBreadcrumbs'] : 100);
        $initialScope = $options['initialScope'] ?? null;
        if (is_array($initialScope)) {
            $scope->setTags(is_array($initialScope['tags'] ?? null) ? $initialScope['tags'] : []);
            $scope->setExtras(is_array($initialScope['extra'] ?? null) ? $initialScope['extra'] : []);
            if (is_array($initialScope['user'] ?? null)) {
                $scope->setUser($initialScope['user']);
            }
            foreach (is_array($initialScope['contexts'] ?? null) ? $initialScope['contexts'] : [] as $key => $context) {
                $scope->setContext((string) $key, is_array($context) ? $context : null);
            }
            if (is_string($initialScope['level'] ?? null)) {
                $scope->setLevel($initialScope['level']);
            }
        }
        $this->scopes = [$scope];
    }

    /**
     * `Sentry.init(options)`: throws on a missing or invalid DSN.
     *
     * @param array<string, mixed> $options
     * @param (callable(string, array<string, mixed>): mixed)|null $fetch the default transport (`options.transport` wins)
     */
    public static function init(array $options, ?callable $fetch = null, ?LoggerInterface $logger = null): self
    {
        if (!is_string($options['dsn'] ?? null) || $options['dsn'] === '') {
            throw new \InvalidArgumentException('Invalid Sentry Dsn: no DSN provided');
        }

        $transport = $options['transport'] ?? $fetch;
        if (!is_callable($transport)) {
            throw new \InvalidArgumentException('Sentry needs a transport');
        }

        return new self($options, $transport, $logger ?? new NullLogger());
    }

    public static function sdkVersion(): string
    {
        static $version = null;
        if ($version === null) {
            $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
            $version = is_array($composer) && is_string($composer['version'] ?? null) ? $composer['version'] : '0.0.0';
        }

        return $version;
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getDsn(): Dsn
    {
        return $this->dsn;
    }

    public function getCurrentScope(): Scope
    {
        return $this->scopes[count($this->scopes) - 1];
    }

    /**
     * Runs the callback with a fork of the current scope, discarded afterwards.
     *
     * @template T
     * @param callable(Scope): T $callback
     * @return T
     */
    public function withScope(callable $callback): mixed
    {
        $this->scopes[] = $this->getCurrentScope()->clone();

        try {
            return $callback($this->getCurrentScope());
        } finally {
            array_pop($this->scopes);
        }
    }

    /** @param callable(Scope): mixed $callback */
    public function configureScope(callable $callback): void
    {
        $callback($this->getCurrentScope());
    }

    public function setTag(string $key, mixed $value): void
    {
        $this->getCurrentScope()->setTag($key, $value);
    }

    /** @param array<string, mixed> $tags */
    public function setTags(array $tags): void
    {
        $this->getCurrentScope()->setTags($tags);
    }

    public function setExtra(string $key, mixed $value): void
    {
        $this->getCurrentScope()->setExtra($key, $value);
    }

    /** @param array<string, mixed> $extras */
    public function setExtras(array $extras): void
    {
        $this->getCurrentScope()->setExtras($extras);
    }

    /** @param array<string, mixed>|null $context */
    public function setContext(string $key, ?array $context): void
    {
        $this->getCurrentScope()->setContext($key, $context);
    }

    /** @param array<string, mixed>|null $user */
    public function setUser(?array $user): void
    {
        $this->getCurrentScope()->setUser($user);
    }

    /** @param array<string, mixed> $breadcrumb */
    public function addBreadcrumb(array $breadcrumb): void
    {
        $this->getCurrentScope()->addBreadcrumb($breadcrumb);
    }

    /**
     * @param array<string, mixed> $hint
     * @return string|null the event id, null when the event was not sent
     */
    public function captureException(\Throwable $exception, array $hint = []): ?string
    {
        return $this->captureEvent([
            'level' => 'error',
            'exception' => ['values' => $this->exceptionValues($exception)],
        ], [...$hint, 'originalException' => $exception]);
    }

    public function captureMessage(string $message, string $level = 'info'): ?string
    {
        $event = ['level' => $level, 'message' => ['formatted' => $message]];
        if (($this->options['attachStacktrace'] ?? false) === true) {
            $event['stacktrace'] = ['frames' => $this->frames(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), null, null)];
        }

        return $this->captureEvent($event, ['syntheticException' => null]);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $hint
     */
    public function captureEvent(array $event, array $hint = []): ?string
    {
        $prepared = $this->prepareEvent($event, $hint);
        if ($prepared === null) {
            return null;
        }

        $this->lastEventId = (string) $prepared['event_id'];
        $this->send($prepared);

        return $this->lastEventId;
    }

    public function lastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /** Events are sent synchronously: there is never anything left to flush. */
    public function flush(?float $timeout = null): bool
    {
        return true;
    }

    /** Disables the client: later events are dropped. */
    public function close(?float $timeout = null): bool
    {
        $this->closed = true;

        return true;
    }

    /**
     * The event as it would be sent (base fields, scope, `beforeSend`), or null when dropped.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $hint
     * @return array<string, mixed>|null
     */
    public function prepareEvent(array $event, array $hint = []): ?array
    {
        if ($this->closed || ($this->options['enabled'] ?? true) === false) {
            return null;
        }

        $sampleRate = $this->options['sampleRate'] ?? null;
        if (is_int($sampleRate) || is_float($sampleRate)) {
            if ($sampleRate <= 0 || ($sampleRate < 1 && mt_rand() / mt_getrandmax() >= $sampleRate)) {
                return null;
            }
        }

        $base = [
            'event_id' => bin2hex(random_bytes(16)),
            'timestamp' => round(microtime(true), 3),
            'platform' => 'php',
            'level' => 'error',
            'server_name' => $this->options['serverName'] ?? $this->options['server_name'] ?? (gethostname() ?: null),
            'environment' => $this->options['environment'] ?? (getenv('SENTRY_ENVIRONMENT') ?: 'production'),
            'release' => $this->options['release'] ?? (getenv('SENTRY_RELEASE') ?: null),
            'dist' => $this->options['dist'] ?? null,
            'sdk' => [
                'name' => self::SDK_NAME,
                'version' => self::sdkVersion(),
                'packages' => [['name' => 'composer:strapi/plugin-sentry', 'version' => self::sdkVersion()]],
            ],
            'contexts' => [
                'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
                'os' => ['name' => PHP_OS_FAMILY, 'version' => php_uname('r'), 'kernel_version' => php_uname('v')],
            ],
        ];

        $event = [...$base, ...$event];
        $event['contexts'] = [...$base['contexts'], ...(is_array($event['contexts']) ? $event['contexts'] : [])];
        $event = array_filter($event, static fn (mixed $value): bool => $value !== null);

        $event = $this->getCurrentScope()->applyToEvent($event, $hint);
        if ($event === null) {
            return null;
        }

        $beforeSend = $this->options['beforeSend'] ?? null;
        if (is_callable($beforeSend)) {
            $result = $beforeSend($event, $hint);
            if (!is_array($result)) {
                return null;
            }
            $event = $result;
        }

        return $event;
    }

    /**
     * The envelope (`application/x-sentry-envelope`) body for an event.
     *
     * @param array<string, mixed> $event
     */
    public function createEnvelope(array $event): string
    {
        $payload = self::json($event);
        $now = microtime(true);
        $header = self::json([
            'event_id' => $event['event_id'] ?? null,
            'sent_at' => gmdate('Y-m-d\TH:i:s', (int) $now) . sprintf('.%03dZ', (int) floor(fmod($now, 1.0) * 1000)),
            'dsn' => (string) $this->dsn,
            'sdk' => ['name' => self::SDK_NAME, 'version' => self::sdkVersion()],
        ]);
        $itemHeader = self::json(['type' => 'event', 'content_type' => 'application/json', 'length' => strlen($payload)]);

        return "{$header}\n{$itemHeader}\n{$payload}\n";
    }

    /** @param array<string, mixed> $event */
    private function send(array $event): void
    {
        $client = self::SDK_NAME . '/' . self::sdkVersion();

        try {
            $response = ($this->transport)($this->dsn->envelopeEndpoint(), [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/x-sentry-envelope',
                    'X-Sentry-Auth' => $this->dsn->authHeader($client),
                    'User-Agent' => $client,
                ],
                'body' => $this->createEnvelope($event),
                'timeout' => is_int($this->options['timeout'] ?? null) || is_float($this->options['timeout'] ?? null) ? $this->options['timeout'] : 2,
            ]);

            $status = is_array($response) && is_int($response['status'] ?? null) ? $response['status'] : null;
            if ($status !== null && ($status < 200 || $status >= 300)) {
                $this->logger->warning("Sentry responded with HTTP {$status} for event {$event['event_id']}");
            } elseif (($this->options['debug'] ?? false) === true) {
                $this->logger->debug("Sentry event {$event['event_id']} sent");
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Could not send the event to Sentry: {$e->getMessage()}");
        }
    }

    /**
     * The exception interface values: the previous exceptions first, the captured one last.
     *
     * @return list<array<string, mixed>>
     */
    private function exceptionValues(\Throwable $exception): array
    {
        $values = [];
        $seen = [];
        for ($e = $exception; $e !== null && !in_array($e, $seen, true); $e = $e->getPrevious()) {
            $seen[] = $e;
            $value = [
                'type' => $e::class,
                'value' => $e->getMessage(),
                'stacktrace' => ['frames' => $this->frames($e->getTrace(), $e->getFile(), $e->getLine())],
            ];
            $values[] = $value;
        }

        $values[0]['mechanism'] = ['type' => 'generic', 'handled' => true];

        return array_reverse($values);
    }

    /**
     * Sentry frames (oldest call first) from a PHP backtrace (most recent call first).
     *
     * @param list<array<string, mixed>> $trace
     * @return list<array<string, mixed>>
     */
    private function frames(array $trace, ?string $file, ?int $line): array
    {
        $frames = [];

        if ($file === null) {
            $first = array_shift($trace);
            $file = is_array($first) && is_string($first['file'] ?? null) ? $first['file'] : null;
            $line = is_array($first) && is_int($first['line'] ?? null) ? $first['line'] : null;
        }

        foreach ($trace as $entry) {
            $frames[] = $this->frame($file, $line, self::functionName($entry));
            $file = is_string($entry['file'] ?? null) ? $entry['file'] : null;
            $line = is_int($entry['line'] ?? null) ? $entry['line'] : null;
        }
        $frames[] = $this->frame($file, $line, null);

        return array_reverse($frames);
    }

    /** @param array<string, mixed> $entry */
    private static function functionName(array $entry): ?string
    {
        $function = is_string($entry['function'] ?? null) ? $entry['function'] : null;
        if ($function === null) {
            return null;
        }
        $class = is_string($entry['class'] ?? null) ? $entry['class'] : null;

        return $class !== null ? $class . '::' . $function : $function;
    }

    /** @return array<string, mixed> */
    private function frame(?string $file, ?int $line, ?string $function): array
    {
        $frame = [
            'filename' => $file !== null ? self::relativePath($file) : '[internal]',
            'abs_path' => $file ?? '[internal]',
            'function' => $function,
            'lineno' => $line ?? 0,
            'in_app' => $file !== null && !str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR),
        ];
        if ($function === null) {
            unset($frame['function']);
        }

        if ($file !== null && $line !== null && $line > 0 && is_file($file) && is_readable($file) && filesize($file) < 1_000_000) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (is_array($lines) && isset($lines[$line - 1])) {
                $index = $line - 1;
                $frame['pre_context'] = array_slice($lines, max(0, $index - self::CONTEXT_LINES), min($index, self::CONTEXT_LINES));
                $frame['context_line'] = $lines[$index];
                $frame['post_context'] = array_slice($lines, $index + 1, self::CONTEXT_LINES);
            }
        }

        return $frame;
    }

    private static function relativePath(string $file): string
    {
        $cwd = getcwd();
        if ($cwd !== false && str_starts_with($file, $cwd . DIRECTORY_SEPARATOR)) {
            return substr($file, strlen($cwd) + 1);
        }

        return $file;
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
