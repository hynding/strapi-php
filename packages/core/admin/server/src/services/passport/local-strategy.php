<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Passport;

use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/services/passport/local-strategy.ts: passport-local's `Strategy` with
 * `{ usernameField: 'email', passwordField: 'password', session: false }` and the admin verify
 * callback. `authenticate()` returns what passport hands to the `authenticate('local', cb)`
 * callback: `[error, user|false, info]`.
 */
final class LocalStrategy
{
    public string $name = 'local';

    /** @var (callable(array{0: mixed, 1: mixed, 2?: mixed}, callable): mixed)|null */
    private $middleware;

    public function __construct(private readonly Strapi $strapi, ?callable $middleware = null)
    {
        $this->middleware = $middleware;
    }

    public static function createLocalStrategy(Strapi $strapi, ?callable $middleware = null): self
    {
        return new self($strapi, $middleware);
    }

    /** @return array{0: mixed, 1: mixed, 2?: mixed} */
    public function authenticate(Context $ctx): array
    {
        $body = $ctx->requestBody();
        $query = $ctx->query();
        $email = self::lookup(is_array($body) ? $body : [], 'email') ?? self::lookup($query, 'email');
        $password = self::lookup(is_array($body) ? $body : [], 'password') ?? self::lookup($query, 'password');

        // passport-local: `if (!username || !password) return this.fail({ message: 'Missing credentials' }, 400)`
        if (!self::truthy($email) || !self::truthy($password)) {
            return [null, false, ['message' => 'Missing credentials']];
        }

        $done = static fn (mixed $error, mixed $user = false, mixed $info = null): array => $info === null ? [$error, $user] : [$error, $user, $info];

        try {
            $result = Utils::getService($this->strapi, 'auth')->checkCredentials([
                'email' => strtolower((string) $email),
                'password' => (string) $password,
            ]);

            if ($this->middleware !== null) {
                return ($this->middleware)($result, $done);
            }

            return $done(...$result);
        } catch (\Throwable $error) {
            return $done($error);
        }
    }

    /** @param array<string, mixed> $obj */
    private static function lookup(array $obj, string $field): mixed
    {
        return $obj[$field] ?? null;
    }

    private static function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0);
    }
}
