<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Auth;

use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of packages/core/core/src/services/auth/index.ts: `strapi.auth`.
 *
 * A strategy is an array `['name' => string, 'authenticate' => callable(Context): array, 'verify' => ?callable(array $auth, mixed $config): void]`
 * where `authenticate` returns `['authenticated' => bool, 'credentials' => mixed, 'ability' => mixed, 'error' => ?string]`.
 *
 * PHP-port note: when the content API has NO registered strategy at all (users-permissions is not
 * ported yet), its requests are treated as public instead of being rejected with 401, so the
 * content API is usable; registering any strategy restores upstream's behaviour. Admin routes get
 * no such fallback: without the admin package nobody can authenticate, so they answer 401 like
 * upstream does for an anonymous request (plugin admin routes rely on this).
 *
 * @phpstan-type Strategy array{name: string, authenticate: callable, verify?: callable}
 * @phpstan-type AuthenticationInfo array{strategy: Strategy, credentials: mixed, ability: mixed}
 */
final class Auth
{
    private const INVALID_STRATEGY_MSG = 'Invalid auth strategy. Expecting an object with properties {name: string, authenticate: function, verify: function}';

    /** @var array<string, list<Strategy>> */
    private array $strategies = [];

    public static function createAuthentication(): self
    {
        return new self();
    }

    /** @param array<string, mixed> $strategy */
    private static function validStrategy(array $strategy): void
    {
        if (!array_key_exists('authenticate', $strategy) || !is_callable($strategy['authenticate'])) {
            throw new \InvalidArgumentException(self::INVALID_STRATEGY_MSG);
        }

        if (array_key_exists('verify', $strategy) && !is_callable($strategy['verify'])) {
            throw new \InvalidArgumentException(self::INVALID_STRATEGY_MSG);
        }
    }

    /** @param Strategy $strategy */
    public function register(string $type, array $strategy): static
    {
        self::validStrategy($strategy);

        $this->strategies[$type][] = $strategy;

        return $this;
    }

    /** @return array<string, list<Strategy>> */
    public function strategies(): array
    {
        return $this->strategies;
    }

    /** The authenticate middleware: `callable(Context $ctx, callable $next): void`. */
    public function authenticate(Context $ctx, callable $next): void
    {
        $route = $ctx->state()->route();

        // use route strategy
        $config = $route['config']['auth'] ?? null;

        if ($config === false) {
            $next();

            return;
        }

        $routeType = $route['info']['type'] ?? null;
        $routeStrategies = is_string($routeType) ? ($this->strategies[$routeType] ?? []) : [];
        $configStrategies = is_array($config) && isset($config['strategies']) && is_array($config['strategies']) ? $config['strategies'] : $routeStrategies;

        $strategiesToUse = [];
        foreach ($configStrategies as $strategy) {
            // Resolve by strategy name
            if (is_string($strategy)) {
                foreach ($routeStrategies as $routeStrategy) {
                    if ($routeStrategy['name'] === $strategy) {
                        $strategiesToUse[] = $routeStrategy;
                    }
                }
            } elseif (is_array($strategy)) {
                // Use the given strategy as is
                self::validStrategy($strategy);
                $strategiesToUse[] = $strategy;
            }
        }

        // PHP port: no strategy registered for the content API → public access (see class doc)
        if ($strategiesToUse === [] && $routeStrategies === [] && $routeType === 'content-api') {
            $next();

            return;
        }

        foreach ($strategiesToUse as $strategy) {
            $result = ($strategy['authenticate'])($ctx);
            $result = is_array($result) ? $result : [];

            $authenticated = $result['authenticated'] ?? false;
            $credentials = $result['credentials'] ?? null;
            $ability = $result['ability'] ?? null;
            $error = $result['error'] ?? null;

            if ($error !== null) {
                $this->unauthorized($ctx, is_string($error) ? $error : (is_object($error) && method_exists($error, 'getMessage') ? $error->getMessage() : 'Unauthorized'));

                return;
            }

            if ($authenticated) {
                $ctx->state()->set('isAuthenticated', true);
                $ctx->state()->set('auth', ['strategy' => $strategy, 'credentials' => $credentials, 'ability' => $ability]);

                $next();

                return;
            }
        }

        $this->unauthorized($ctx, 'Missing or invalid credentials');
    }

    /**
     * @param AuthenticationInfo|array<string, mixed>|null $auth
     * @param mixed $config the route `config.auth` (`false`, `['scope' => [...]]`)
     */
    public function verify(?array $auth, mixed $config = [], ?string $routeType = null): mixed
    {
        if ($config === false) {
            return null;
        }

        if ($auth === null || $auth === []) {
            // PHP port: no strategy registered for the content API → public access (see class doc)
            if ($routeType === 'content-api' && ($this->strategies[$routeType] ?? []) === []) {
                return null;
            }

            throw new UnauthorizedError();
        }

        $verify = $auth['strategy']['verify'] ?? null;
        if (is_callable($verify)) {
            return $verify($auth, $config);
        }

        return null;
    }

    private function unauthorized(Context $ctx, string $message): void
    {
        $error = new UnauthorizedError($message);
        $ctx->setStatus(401);
        $ctx->setBody(['data' => null, 'error' => $error->toArray()]);
    }
}
