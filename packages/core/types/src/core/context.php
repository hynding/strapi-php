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

    /** @param array<string, mixed> $query */
    public function setQuery(array $query): void;

    /** @return mixed parsed request body (JSON object as array, multipart as ['data' => ..., 'files' => ...]) */
    public function requestBody(): mixed;

    /** @return array<string, \Psr\Http\Message\UploadedFileInterface> */
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
}
