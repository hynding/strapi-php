<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

/**
 * Port of packages/core/core/src/services/server/koa-methods.ts: the generated `ctx.<status>()`
 * error helpers, one per 4xx/5xx status of Node's `http.STATUS_CODES`.
 */
final class KoaMethods
{
    /** Node `http.STATUS_CODES` 4xx/5xx. */
    public const STATUS_CODES = [
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout',
        409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Payload Too Large',
        414 => 'URI Too Long', 415 => 'Unsupported Media Type', 416 => 'Range Not Satisfiable', 417 => 'Expectation Failed',
        418 => "I'm a Teapot", 421 => 'Misdirected Request', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency',
        425 => 'Too Early', 426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons', 500 => 'Internal Server Error',
        501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates', 507 => 'Insufficient Storage', 508 => 'Loop Detected',
        509 => 'Bandwidth Limit Exceeded', 510 => 'Not Extended', 511 => 'Network Authentication Required',
    ];

    /** lodash camelCase of the status name: "Not Found" → notFound, "I'm a Teapot" → imATeapot. */
    public static function methodName(string $statusName): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $statusName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        foreach ($words as $i => $word) {
            $lower = strtolower($word);
            $out .= $i === 0 ? $lower : ucfirst($lower);
        }

        return $out;
    }

    /** @return array<string, int> methodName => code */
    public static function errorMethodEntries(): array
    {
        static $entries = null;
        if ($entries === null) {
            $entries = [];
            foreach (self::STATUS_CODES as $code => $statusName) {
                $entries[self::methodName($statusName)] = $code;
            }
        }

        return $entries;
    }

    public static function codeForMethodName(string $name): ?int
    {
        return self::errorMethodEntries()[$name] ?? null;
    }

    public static function statusText(int $code): string
    {
        return self::STATUS_CODES[$code] ?? 'Error';
    }
}
