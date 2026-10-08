<?php

declare(strict_types=1);

namespace Strapi\Types\Core;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Koa-style request context handed to middlewares, policies and controllers.
 * Mirrors what upstream reads off Koa's ctx: request, params, query, state, body, status.
 *
 * The response helpers core's server adds to Koa's context (services/server/koa.ts) are
 * available on the implementation:
 *
 * @method void badRequest(string|array<string, mixed>|null $response = null, mixed $details = [])
 * @method void unauthorized(string|array<string, mixed>|null $response = null, mixed $details = [])
 * @method void forbidden(string|array<string, mixed>|null $response = null, mixed $details = [])
 * @method void notFound(string|array<string, mixed>|null $response = null, mixed $details = [])
 * @method void internalServerError(string|array<string, mixed>|null $response = null, mixed $details = [])
 * @method void notImplemented(string|array<string, mixed>|null $response = null, mixed $details = [])
 * @method void send(mixed $data, int $status = 200)
 * @method void created(mixed $data = null)
 * @method void deleted(mixed $data = null)
 */
interface Context
{
    public function request(): ServerRequestInterface;

    /** @return array<string, string> route params (:id → ['id' => '...']) */
    public function params(): array;

    public function param(string $name): ?string;

    /** @return array<string, mixed> parsed with qs semantics (filters[a][$eq]=1 → nested arrays) */
    public function query(): array;

    /** @param array<array-key, mixed> $query */
    public function setQuery(array $query): void;

    /**
     * The parsed request body (JSON object as array, multipart as ['data' => ..., 'files' => ...]).
     *
     * JSON `{}` and `[]` both read as `[]`, unless `$emptyObjects`: then an empty JSON object is a
     * `Strapi\Utils\EmptyObject` (for validation that must tell an object from an array).
     *
     * @return mixed
     */
    public function requestBody(bool $emptyObjects = false): mixed;

    /** @return array<string, \Psr\Http\Message\UploadedFileInterface|list<\Psr\Http\Message\UploadedFileInterface>> a list when the multipart field is repeated (koa-body) */
    public function files(): array;

    public function method(): string;

    public function path(): string;

    public function url(): string;

    public function header(string $name): ?string;

    public function ip(): string;

    /** Koa `ctx.request.secure`: the protocol is https (honouring `X-Forwarded-Proto` when `server.proxy.koa` is on). */
    public function secure(): bool;

    /** Koa `ctx.cookies`. */
    public function cookies(): Cookies;

    /** Response body to serialize (array → JSON). */
    public function body(): mixed;

    public function setBody(mixed $body): void;

    public function status(): int;

    public function setStatus(int $status): void;

    public function setHeader(string $name, string $value): void;

    public function appendHeader(string $name, string $value): void;

    /** @return array<string, list<string>> */
    public function responseHeaders(): array;

    public function type(): ?string;

    public function setType(string $mime): void;

    /** Per-request state (auth, user, route). */
    public function state(): State;

    public function throw(int $status, string $message = ''): never;

    public function is(string ...$types): bool;

    public function redirect(string $url, int $status = 302): void;

    // --- koa.ts custom response methods: `ctx.<name>(message|details, details)` writes the error envelope ---

    /** @param string|array<string, mixed>|null $response */
    public function badRequest(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function unauthorized(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function paymentRequired(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function forbidden(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function notFound(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function methodNotAllowed(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function notAcceptable(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function requestTimeout(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function conflict(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function gone(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function payloadTooLarge(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function unsupportedMediaType(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function unprocessableEntity(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function tooManyRequests(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function internalServerError(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function notImplemented(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function badGateway(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function serviceUnavailable(string|array|null $response = null, mixed $details = []): void;

    /** @param string|array<string, mixed>|null $response */
    public function gatewayTimeout(string|array|null $response = null, mixed $details = []): void;
}
