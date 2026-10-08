<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Strapi\Core\Services\Errors;
use Strapi\Types\Core\Context as ContextContract;
use Strapi\Utils\EmptyObject;
use Strapi\Utils\Errors\HttpError;

/**
 * The Koa-style request context (port of what upstream reads off `ctx` plus the custom response
 * methods of services/server/koa.ts): wraps a PSR-7 ServerRequest and accumulates the response.
 *
 * Koa semantics kept: `status` defaults to 404 until a body or status is set (`_explicitStatus`),
 * setting a body on a 404 switches to 200, `null` bodies with 200 become 204, `ctx.notFound()` /
 * `ctx.badRequest()`... write the error envelope, `send/created/deleted` as upstream.
 */
final class Context implements ContextContract
{
    /** @var array<string, string> */
    private array $params = [];

    /** @var array<string, mixed> */
    private array $query = [];

    private bool $queryParsed = false;

    private mixed $requestBody = null;

    /** @var array{value: mixed}|null {@see self::requestBody()} without markers, computed once */
    private ?array $plainRequestBody = null;

    private bool $bodyParsed = false;

    /** @var array<string, UploadedFileInterface|list<UploadedFileInterface>> */
    private array $files = [];

    private mixed $body = null;

    private int $status = 404;

    private bool $explicitStatus = false;

    /** @var array<string, list<string>> */
    private array $headers = [];

    private ?string $type = null;

    private readonly State $state;

    private ServerRequestInterface $request;

    private ?Cookies $cookies = null;

    /**
     * @param array{proxy?: bool, keys?: list<string>|null} $options Koa app settings: `proxy`
     *        (`server.proxy.koa`, trust `X-Forwarded-*`) and `keys` (`server.app.keys`, cookie signing)
     */
    public function __construct(ServerRequestInterface $request, private readonly array $options = [])
    {
        $this->request = $request;
        $this->state = new State();
    }

    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    public function withRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    // --- request --------------------------------------------------------------------------

    public function params(): array
    {
        return $this->params;
    }

    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    /** @param array<string, string> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function query(): array
    {
        if (!$this->queryParsed) {
            // default parser when the strapi::query middleware is not mounted
            parse_str($this->request->getUri()->getQuery(), $parsed);
            $this->setQuery($parsed);
        }

        return $this->query;
    }

    public function setQuery(array $query): void
    {
        // query keys are parameter names: `?0=a` keeps working, the key is just stored as given
        $this->query = [];
        foreach ($query as $key => $value) {
            $this->query[(string) $key] = $value;
        }
        $this->queryParsed = true;
    }

    public function querystring(): string
    {
        return $this->request->getUri()->getQuery();
    }

    public function requestBody(bool $emptyObjects = false): mixed
    {
        if (!$this->bodyParsed) {
            $parsed = $this->request->getParsedBody();
            $this->setRequestBody($parsed);
        }
        if ($emptyObjects) {
            return $this->requestBody;
        }

        // the body as `json_decode($json, true)` gives it: every `{}` marker back to `[]`
        return ($this->plainRequestBody ??= ['value' => EmptyObject::toArrays($this->requestBody)])['value'];
    }

    /** `$body` may hold {@see EmptyObject} markers (strapi::body decodes JSON `{}` to one). */
    public function setRequestBody(mixed $body): void
    {
        $this->requestBody = $body;
        $this->plainRequestBody = null;
        $this->bodyParsed = true;
    }

    public function files(): array
    {
        return $this->files;
    }

    /** @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files a list when the field is repeated (koa-body) */
    public function setFiles(array $files): void
    {
        $this->files = $files;
    }

    public function method(): string
    {
        return strtoupper($this->request->getMethod());
    }

    public function path(): string
    {
        $path = $this->request->getUri()->getPath();

        return $path === '' ? '/' : $path;
    }

    public function url(): string
    {
        $uri = $this->request->getUri();
        $query = $uri->getQuery();

        return $this->path() . ($query !== '' ? "?{$query}" : '');
    }

    public function origin(): string
    {
        $uri = $this->request->getUri();
        $port = $uri->getPort();

        return $uri->getScheme() . '://' . $uri->getHost() . ($port !== null ? ":{$port}" : '');
    }

    public function host(): string
    {
        return $this->request->getHeaderLine('Host') ?: $this->request->getUri()->getHost();
    }

    public function header(string $name): ?string
    {
        if (!$this->request->hasHeader($name)) {
            return null;
        }

        return $this->request->getHeaderLine($name);
    }

    /** Koa `ctx.get(name)`: `''` when missing. */
    public function get(string $name): string
    {
        return $this->request->getHeaderLine($name);
    }

    public function ip(): string
    {
        $server = $this->request->getServerParams();

        return (string) ($this->request->getAttribute('ip') ?? $server['REMOTE_ADDR'] ?? '');
    }

    /**
     * Koa `request.protocol`: `https` for a TLS connection; behind a trusted proxy
     * (`server.proxy.koa`) the first `X-Forwarded-Proto` value; `http` otherwise.
     */
    public function protocol(): string
    {
        $server = $this->request->getServerParams();
        $https = $server['HTTPS'] ?? null;
        if ($this->request->getUri()->getScheme() === 'https' || (is_string($https) && $https !== '' && strtolower($https) !== 'off')) {
            return 'https';
        }

        if (($this->options['proxy'] ?? false) !== true) {
            return 'http';
        }

        $proto = $this->request->getHeaderLine('X-Forwarded-Proto');
        if ($proto === '') {
            return 'http';
        }

        return trim(explode(',', $proto, 2)[0]);
    }

    public function secure(): bool
    {
        return $this->protocol() === 'https';
    }

    public function cookies(): Cookies
    {
        if ($this->cookies === null) {
            $keys = $this->options['keys'] ?? null;
            $keys = is_array($keys) && $keys !== [] ? array_values(array_map('strval', $keys)) : null;
            $this->cookies = new Cookies($this, $keys, $this->secure());
        }

        return $this->cookies;
    }

    public function is(string ...$types): bool
    {
        $contentType = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'))[0]));
        if ($contentType === '') {
            return false;
        }
        foreach ($types as $type) {
            $type = strtolower($type);
            $full = match ($type) {
                'json' => 'application/json',
                'multipart' => 'multipart/form-data',
                'urlencoded' => 'application/x-www-form-urlencoded',
                'text' => 'text/plain',
                default => $type,
            };
            if ($contentType === $full || ($type === 'json' && str_ends_with($contentType, '+json'))) {
                return true;
            }
            if (str_contains($full, '*') && fnmatch($full, $contentType)) {
                return true;
            }
        }

        return false;
    }

    public function state(): State
    {
        return $this->state;
    }

    // --- response -------------------------------------------------------------------------

    public function body(): mixed
    {
        return $this->body;
    }

    public function setBody(mixed $body): void
    {
        $this->body = $body;

        if ($body === null) {
            // Koa: a null body on a non-empty status becomes 204 (and the status is then explicit)
            if (!in_array($this->status, [204, 205, 304], true)) {
                $this->status = 204;
                $this->explicitStatus = true;
            }

            return;
        }

        // Koa: setting a body on an implicit status switches to 200 and makes the status explicit
        if (!$this->explicitStatus) {
            $this->status = 200;
        }
        $this->explicitStatus = true;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
        $this->explicitStatus = true;
        if (in_array($status, [204, 205, 304], true)) {
            $this->body = null;
        }
    }

    public function hasExplicitStatus(): bool
    {
        return $this->explicitStatus;
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers[strtolower($name)] = [$value];
    }

    /** Koa `ctx.set(name, value)`. */
    public function set(string $name, string $value): void
    {
        $this->setHeader($name, $value);
    }

    public function appendHeader(string $name, string $value): void
    {
        $this->headers[strtolower($name)][] = $value;
    }

    public function removeHeader(string $name): void
    {
        unset($this->headers[strtolower($name)]);
    }

    public function responseHeader(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;

        return $values === null ? null : implode(', ', $values);
    }

    public function responseHeaders(): array
    {
        return $this->headers;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function setType(string $mime): void
    {
        $this->type = $mime;
    }

    public function redirect(string $url, int $status = 302): void
    {
        $this->setStatus($status);
        $this->setHeader('Location', $url);
        $this->setType('text/html; charset=utf-8');
        $this->body = 'Redirecting to ' . htmlspecialchars($url) . '.';
    }

    public function throw(int $status, string $message = ''): never
    {
        throw new HttpError($status, $message);
    }

    // --- koa.ts custom methods ---------------------------------------------------------------

    /** @param string|array<string, mixed> $response */
    private function httpError(int $code, string|array|null $response = null, mixed $details = []): void
    {
        $statusName = KoaMethods::statusText($code);
        $message = is_string($response) ? $response : $statusName;
        $error = new HttpError($code, $message, is_array($details) ? $details : ['details' => $details], self::errorName($code));
        ['status' => $status, 'body' => $body] = Errors::formatHttpError($error);
        if (is_array($response)) {
            $body['error']['message'] = $statusName;
            $body['error']['details'] = $response;
        }
        $this->setStatus($status);
        $this->body = $body;
    }

    /** @param string|array<string, mixed>|null $response */
    public function badRequest(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(400, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function unauthorized(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(401, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function paymentRequired(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(402, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function forbidden(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(403, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function notFound(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(404, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function methodNotAllowed(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(405, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function notAcceptable(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(406, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function requestTimeout(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(408, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function conflict(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(409, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function gone(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(410, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function payloadTooLarge(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(413, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function unsupportedMediaType(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(415, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function unprocessableEntity(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(422, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function tooManyRequests(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(429, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function internalServerError(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(500, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function notImplemented(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(501, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function badGateway(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(502, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function serviceUnavailable(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(503, $response, $details);
    }

    /** @param string|array<string, mixed>|null $response */
    public function gatewayTimeout(string|array|null $response = null, mixed $details = []): void
    {
        $this->httpError(504, $response, $details);
    }

    /**
     * Any other 4xx/5xx `ctx.<camelCasedStatus>()` helper.
     *
     * @param list<mixed> $args
     */
    public function __call(string $name, array $args): void
    {
        $code = KoaMethods::codeForMethodName($name);
        if ($code === null) {
            throw new \BadMethodCallException("Call to undefined method Context::{$name}()");
        }
        $this->httpError($code, $args[0] ?? null, $args[1] ?? []);
    }

    /** http-errors derives the error name from the status text: "Not Found" → NotFoundError. */
    private static function errorName(int $code): string
    {
        $name = str_replace(' ', '', ucwords(preg_replace('/[^A-Za-z0-9 ]/', '', KoaMethods::statusText($code)) ?? ''));

        return str_ends_with($name, 'Error') ? $name : $name . 'Error';
    }

    public function send(mixed $data, int $status = 200): void
    {
        $this->setStatus($status);
        $this->body = $data;
    }

    public function created(mixed $data = null): void
    {
        $this->setStatus(201);
        $this->body = $data;
    }

    public function deleted(mixed $data = null): void
    {
        if ($data === null) {
            $this->setStatus(204);
        } else {
            $this->setStatus(200);
            $this->body = $data;
        }
    }

    // --- PSR-7 output -----------------------------------------------------------------------

    public function toResponse(): ResponseInterface
    {
        $status = $this->status;
        $body = $this->body;
        $headers = $this->headers;

        if ($body === null && !isset($headers['content-type']) && $this->type === null) {
            // Koa: an empty body with an implicit status is a 404 (handled by the errors middleware); with
            // a 2xx explicit status stays empty
            $response = new Response($status);
            foreach ($headers as $name => $values) {
                $response = $response->withHeader($name, $values);
            }

            return $response->withHeader('Content-Length', '0');
        }

        if ($body instanceof \Psr\Http\Message\StreamInterface) {
            $stream = $body;
            $contentType = $this->type ?? 'application/octet-stream';
        } elseif (is_string($body)) {
            $stream = Stream::create($body);
            $contentType = $this->type ?? (str_starts_with(ltrim($body), '<') ? 'text/html; charset=utf-8' : 'text/plain; charset=utf-8');
        } elseif ($body === null) {
            $stream = Stream::create('');
            $contentType = $this->type ?? 'text/plain; charset=utf-8';
        } else {
            $stream = Stream::create((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            $contentType = $this->type ?? 'application/json; charset=utf-8';
        }

        if (in_array($status, [204, 205, 304], true)) {
            $stream = Stream::create('');
        }

        $response = new Response($status);
        foreach ($headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }
        if (!$response->hasHeader('Content-Type') && !in_array($status, [204, 304], true)) {
            $response = $response->withHeader('Content-Type', $contentType);
        }
        if (!$response->hasHeader('Content-Length') && !in_array($status, [204, 304], true)) {
            $size = $stream->getSize();
            if ($size !== null) {
                $response = $response->withHeader('Content-Length', (string) $size);
            }
        }

        return $response->withBody($stream);
    }
}
