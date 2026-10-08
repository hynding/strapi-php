<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

/**
 * A function of the test process passed to the instance (`{"$callback": {"url", "id"}}`, see
 * {@see Bridge}): `strapi.db.lifecycles.subscribe({ afterCreate: jest.fn() })` subscribes a
 * closure that POSTs its JSON-exported arguments to the test's callback server (lib/bridge.js)
 * and returns the function's result, or throws its rejection as a \RuntimeException.
 */
final readonly class Callback
{
    public function __construct(private string $url, private int $id)
    {
    }

    public function __invoke(mixed ...$args): mixed
    {
        return $this->call(array_values($args))['result'];
    }

    /**
     * @param list<mixed> $args
     *
     * @return array{result: mixed, args: list<mixed>} the result and the arguments as the function
     *                                                 left them (JSON), to copy its mutations back
     */
    public function call(array $args): array
    {
        $payload = (string) json_encode(['id' => $this->id, 'args' => Bridge::export($args)], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
            'content' => $payload,
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($this->url, false, $context);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException('api-tests callback: no answer from the test process');
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            throw new \RuntimeException((string) ($decoded['error']['message'] ?? 'Error'));
        }

        $after = is_array($decoded['args'] ?? null) ? array_values($decoded['args']) : [];

        return ['result' => $decoded['result'] ?? null, 'args' => $after];
    }
}
