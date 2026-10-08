<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/middlewares/session.ts (koa-session, minimal): a signed cookie
 * session (`koa.sess`) available as `$ctx->state()->get(Session::STATE_KEY)` (an array); it is written
 * back when modified. Requires `server.app.keys`.
 *
 * Koa keeps `ctx.session` apart from `ctx.state.session` (where the admin and users-permissions
 * strategies expose the current auth session `{ id }`), so the koa session has its own state key.
 */
final class Session
{
    /** The state key holding koa-session's `ctx.session`. */
    public const STATE_KEY = 'koaSession';

    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'key' => 'koa.sess',
        'maxAge' => 86400000,
        'autoCommit' => true,
        'overwrite' => true,
        'httpOnly' => true,
        'signed' => true,
        'rolling' => false,
        'renew' => false,
        'sameSite' => null,
    ];

    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        $keys = $strapi->config()->get('server.app.keys');
        if (!is_array($keys) || $keys === [] || in_array('', array_map('strval', $keys), true)) {
            throw new \RuntimeException("App keys are required. Please set app.keys in config/server.js (ex: keys: ['myKeyA', 'myKeyB'])");
        }

        $options = [...self::DEFAULTS, 'secure' => $strapi->config()->get('environment') === 'production', ...$config];
        $key = (string) $options['key'];
        $secret = (string) $keys[0];

        return static function (Context $ctx, callable $next) use ($options, $key, $secret): void {
            // read the Cookie header: PHP's cookie params turn the `.` of `koa.sess` into `_`
            $cookies = $ctx->cookies();
            $session = [];
            $raw = $cookies->get($key, ['signed' => false]);
            $sig = $cookies->get($key . '.sig', ['signed' => false]);
            if (is_string($raw) && $raw !== '' && is_string($sig) && hash_equals(self::sign($key . '=' . $raw, $secret), $sig)) {
                $decoded = json_decode(base64_decode($raw, true) ?: '', true);
                $session = is_array($decoded) ? $decoded : [];
            }
            $original = $session;
            $ctx->state()->set(self::STATE_KEY, $session);

            $next();

            $updated = $ctx->state()->get(self::STATE_KEY);
            $updated = is_array($updated) ? $updated : [];
            if ($updated === $original && !$options['rolling']) {
                return;
            }

            $expires = gmdate('D, d M Y H:i:s \G\M\T', time() + (int) ($options['maxAge'] / 1000));
            $attributes = "Path=/; Expires={$expires}" . ($options['httpOnly'] ? '; HttpOnly' : '') . ($options['secure'] ? '; Secure' : '')
                . ($options['sameSite'] ? '; SameSite=' . ucfirst((string) $options['sameSite']) : '');
            if ($updated === []) {
                $ctx->appendHeader('Set-Cookie', "{$key}=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT");
                $ctx->appendHeader('Set-Cookie', "{$key}.sig=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT");

                return;
            }
            $value = base64_encode((string) json_encode($updated));
            $ctx->appendHeader('Set-Cookie', "{$key}={$value}; {$attributes}");
            $ctx->appendHeader('Set-Cookie', "{$key}.sig=" . self::sign($key . '=' . $value, $secret) . "; {$attributes}");
        };
    }

    private static function sign(string $data, string $secret): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha1', $data, $secret, true)), '+/', '-_'), '=');
    }
}
