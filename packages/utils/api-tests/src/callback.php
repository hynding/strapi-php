<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

/**
 * A function of the test process passed to the instance (`{"$callback": {"url", "id"}}`, see
 * {@see Bridge}): a listener (`strapi.eventHub.on('entry.create', fn)`), a condition handler, a
 * document-service middleware. Calling it POSTs its JSON-exported arguments to the test's callback
 * server (lib/bridge.js) and returns the function's result, or throws its rejection as a
 * {@see CallbackError}.
 *
 * The function may call the instance back while it runs (`next()`, `strapi.documents(uid).findMany()`
 * in a GraphQL resolver). The worker is busy with the call that invoked it, so the callback server
 * answers with those calls instead (`{"session", "steps"}`): they are replayed here, through the
 * bridge, and their results posted back (`{"session", "reply"}`) until the function settles.
 * Arguments without a data form (a closure, a service) reach it as handles ({@see Bridge::exportArgs()}).
 */
final readonly class Callback
{
    public function __construct(private string $url, private int $id, private ?Bridge $bridge = null)
    {
    }

    public function __invoke(mixed ...$args): mixed
    {
        return $this->call(array_values($args))['result'];
    }

    /**
     * @param list<mixed> $args
     *
     * @return array{result: mixed, args: list<mixed>, sent: mixed} the result, and the arguments as
     *         the function left them (JSON) and as they were sent, to copy its mutations back
     */
    public function call(array $args): array
    {
        $sent = $this->bridge?->exportArgs($args) ?? Bridge::export($args);
        $decoded = $this->post(['id' => $this->id, 'args' => $sent]);

        while (isset($decoded['steps']) && is_array($decoded['steps'])) {
            /** @var list<array<string, mixed>> $steps */
            $steps = array_values($decoded['steps']);
            $reply = $this->bridge?->handleNested(['steps' => $steps])['body']
                ?? ['error' => ['name' => 'Error', 'message' => 'api-tests callback: this function cannot call the instance back']];
            $decoded = $this->post(['session' => $decoded['session'] ?? null, 'reply' => $reply]);
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $token = $decoded['error']['token'] ?? null;

            throw new CallbackError((string) ($decoded['error']['message'] ?? 'Error'), is_int($token) ? $token : null);
        }

        $after = is_array($decoded['args'] ?? null) ? array_values($decoded['args']) : [];

        return ['result' => $decoded['result'] ?? null, 'args' => $after, 'sent' => $sent];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(array $body): array
    {
        $payload = (string) json_encode($body, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
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

        return $decoded;
    }
}
