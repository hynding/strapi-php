<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry\Sdk;

/**
 * Not an upstream file (part of the `@sentry/node` replacement): a parsed Sentry DSN,
 * `{PROTOCOL}://{PUBLIC_KEY}[:{SECRET_KEY}]@{HOST}[:{PORT}]{PATH}/{PROJECT_ID}`, as `dsnFromString`
 * in `@sentry/utils` parses it (an invalid DSN throws, which makes the plugin log a warning).
 */
final readonly class Dsn
{
    private function __construct(
        public string $protocol,
        public string $publicKey,
        public string $secretKey,
        public string $host,
        public string $port,
        public string $path,
        public string $projectId,
    ) {
    }

    public static function parse(string $dsn): self
    {
        $match = preg_match('~^(?:(\w+):)//(?:(\w+)(?::(\w+)?)?@)([\w.-]+)(?::(\d+))?/(.+)$~', $dsn, $m);
        if ($match !== 1) {
            throw new \InvalidArgumentException("Invalid Sentry Dsn: {$dsn}");
        }

        [, $protocol, $publicKey, $secretKey, $host, $port, $lastPath] = array_pad($m, 7, '');

        $path = '';
        $projectId = $lastPath;
        $split = explode('/', $projectId);
        if (count($split) > 1) {
            $path = implode('/', array_slice($split, 0, -1));
            $projectId = (string) array_pop($split);
        }

        if ($projectId !== '') {
            if (preg_match('/^\d+/', $projectId, $projectMatch) === 1) {
                $projectId = $projectMatch[0];
            }
        }

        if (!in_array($protocol, ['http', 'https'], true)) {
            throw new \InvalidArgumentException("Invalid Sentry Dsn: Invalid protocol {$protocol}");
        }
        if ($publicKey === '' || $host === '' || $projectId === '') {
            throw new \InvalidArgumentException('Invalid Sentry Dsn: missing public key, host or project id');
        }
        if (preg_match('/^\d+$/', $projectId) !== 1) {
            throw new \InvalidArgumentException("Invalid Sentry Dsn: Invalid projectId {$projectId}");
        }

        return new self($protocol, $publicKey, $secretKey, $host, $port, $path, $projectId);
    }

    /** `https://<host>[:<port>][/<path>]/api/<project>/envelope/` */
    public function envelopeEndpoint(): string
    {
        $port = $this->port !== '' ? ":{$this->port}" : '';
        $path = $this->path !== '' ? "/{$this->path}" : '';

        return "{$this->protocol}://{$this->host}{$port}{$path}/api/{$this->projectId}/envelope/";
    }

    /** The `X-Sentry-Auth` header value. */
    public function authHeader(string $client): string
    {
        $parts = ['sentry_version=7', "sentry_client={$client}", "sentry_key={$this->publicKey}"];
        if ($this->secretKey !== '') {
            $parts[] = "sentry_secret={$this->secretKey}";
        }

        return 'Sentry ' . implode(', ', $parts);
    }

    public function __toString(): string
    {
        $secret = $this->secretKey !== '' ? ":{$this->secretKey}" : '';
        $port = $this->port !== '' ? ":{$this->port}" : '';
        $path = $this->path !== '' ? "{$this->path}/" : '';

        return "{$this->protocol}://{$this->publicKey}{$secret}@{$this->host}{$port}/{$path}{$this->projectId}";
    }
}
