<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

/** Port of packages/core/core/src/middlewares/index.ts: the internal `strapi::` middleware factories. */
final class Middlewares
{
    /** @return array<string, callable> */
    public static function all(): array
    {
        return [
            'compression' => new Compression(),
            'cors' => new Cors(),
            'errors' => new Errors(),
            'favicon' => new Favicon(),
            'ip' => new Ip(),
            'logger' => new Logger(),
            'poweredBy' => new PoweredBy(),
            'body' => new Body(),
            'query' => new Query(),
            'responseTime' => new ResponseTime(),
            'responses' => new Responses(),
            'security' => new Security(),
            'session' => new Session(),
            'public' => new PublicStatic(),
        ];
    }
}
