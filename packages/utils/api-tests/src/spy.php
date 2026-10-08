<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

/**
 * A service object with one or more methods replaced by a jest mock living in the test process:
 * `jest.spyOn(strapi.plugin('email').service('email'), 'send').mockResolvedValue()` (lib/bridge.js
 * posts a `spy` step, {@see Bridge}). A spied method POSTs its JSON-exported arguments to the
 * test's callback server and returns the mock's result, or throws its rejection as a
 * \RuntimeException; every other method and property goes to the original object.
 */
final class Spy
{
    /** @var array<string, array{url: string, id: int}> method => callback */
    private array $methods = [];

    public function __construct(public readonly object $original)
    {
    }

    public function spy(string $method, string $url, int $id): void
    {
        $this->methods[$method] = ['url' => $url, 'id' => $id];
    }

    /** @return bool whether a spied method is left */
    public function unspy(string $method): bool
    {
        unset($this->methods[$method]);

        return $this->methods !== [];
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        $callback = $this->methods[$name] ?? null;
        if ($callback === null) {
            return $this->original->{$name}(...$args);
        }

        $payload = (string) json_encode(['id' => $callback['id'], 'args' => Bridge::export(array_values($args))], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
            'content' => $payload,
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($callback['url'], false, $context);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException("api-tests spy: no answer from the test process for {$name}()");
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            throw new \RuntimeException((string) ($decoded['error']['message'] ?? 'Error'));
        }

        return $decoded['result'] ?? null;
    }

    public function __get(string $name): mixed
    {
        return $this->original->{$name};
    }

    public function __isset(string $name): bool
    {
        return isset($this->original->{$name});
    }
}
