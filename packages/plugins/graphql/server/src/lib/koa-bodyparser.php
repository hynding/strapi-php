<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib;

use Strapi\Core\Services\Server\Context as ServerContext;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\HttpError;

/**
 * `koa-bodyparser` 4.4 (with co-body): parses JSON (strict: objects and arrays only) and
 * urlencoded bodies into `ctx.request.body`; anything else leaves `{}`. Options: `enableTypes`,
 * `jsonLimit`, `formLimit`, `strict`, `extendTypes`.
 */
final class KoaBodyparser
{
    private const array JSON_TYPES = [
        'application/json',
        'application/json-patch+json',
        'application/vnd.api+json',
        'application/csp-report',
        'application/scim+json',
    ];

    private const array FORM_TYPES = ['application/x-www-form-urlencoded'];

    private const array TEXT_TYPES = ['text/plain'];

    private static function bytes(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\s*(\d+(?:\.\d+)?)\s*(b|kb|mb|gb)?\s*$/i', $value, $m) === 1) {
            $factor = match (strtolower($m[2] ?? 'b')) {
                'kb' => 1024,
                'mb' => 1024 ** 2,
                'gb' => 1024 ** 3,
                default => 1,
            };

            return (int) ((float) $m[1] * $factor);
        }

        return $default;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value)) : [];
    }

    /**
     * @param list<string> $types
     */
    private static function is(?string $contentType, array $types): bool
    {
        if ($contentType === null || $contentType === '') {
            return false;
        }
        $essence = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($essence, $types, true);
    }

    /**
     * @param array<string, mixed> $options
     * @return \Closure(Context, callable): mixed
     */
    public static function create(array $options = []): \Closure
    {
        $enableTypes = is_array($options['enableTypes'] ?? null) ? $options['enableTypes'] : ['json', 'form'];
        $jsonLimit = self::bytes($options['jsonLimit'] ?? null, 1024 * 1024);
        $formLimit = self::bytes($options['formLimit'] ?? null, 56 * 1024);
        $textLimit = self::bytes($options['textLimit'] ?? null, 1024 * 1024);
        $strict = ($options['strict'] ?? true) !== false;
        $extendTypes = is_array($options['extendTypes'] ?? null) ? $options['extendTypes'] : [];
        $jsonTypes = [...self::JSON_TYPES, ...self::stringList($extendTypes['json'] ?? null)];
        $formTypes = [...self::FORM_TYPES, ...self::stringList($extendTypes['form'] ?? null)];
        $textTypes = [...self::TEXT_TYPES, ...self::stringList($extendTypes['text'] ?? null)];

        return static function (Context $ctx, callable $next) use ($enableTypes, $jsonLimit, $formLimit, $textLimit, $strict, $jsonTypes, $formTypes, $textTypes): mixed {
            if (!$ctx instanceof ServerContext) {
                return $next();
            }

            $contentType = $ctx->header('Content-Type');
            $read = static function (int $limit) use ($ctx): string {
                $stream = $ctx->request()->getBody();
                if ($stream->isSeekable()) {
                    $stream->rewind();
                }
                $raw = (string) $stream;
                if (strlen($raw) > $limit) {
                    throw new HttpError(413, 'request entity too large', [], 'PayloadTooLargeError');
                }

                return $raw;
            };

            $body = [];
            if (in_array('json', $enableTypes, true) && self::is($contentType, $jsonTypes)) {
                $raw = trim($read($jsonLimit));
                if ($raw !== '') {
                    if ($strict && !in_array($raw[0], ['{', '['], true)) {
                        throw new HttpError(400, 'invalid JSON, only supports object and array');
                    }
                    try {
                        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        throw new HttpError(400, $e->getMessage());
                    }
                }
            } elseif (in_array('form', $enableTypes, true) && self::is($contentType, $formTypes)) {
                parse_str($read($formLimit), $parsed);
                $body = $parsed;
            } elseif (in_array('text', $enableTypes, true) && self::is($contentType, $textTypes)) {
                $body = $read($textLimit);
            }

            $ctx->setRequestBody($body);

            return $next();
        };
    }
}
