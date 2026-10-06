<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Scope checks used by the restricted-relation visitors (sanitize/validate).
 *
 * Upstream calls the global `strapi.auth.verify(auth, { scope })` and reads `strapi.contentTypes`.
 * Here the `$auth` value handed to the sanitizers decides:
 *  - `null` / `false`: the restricted-relation visitors are not applied at all (as upstream);
 *  - an array or object exposing `ability` (`callable(string $action, mixed $subject = null, ?string $field = null): bool`)
 *    is asked `ability($scope)` — the CASL-style check the content API token strategy performs;
 *  - an array or object exposing `verify` (`callable(string $scope): bool`, or throwing when denied) is asked directly;
 *  - otherwise the process-wide verifier registered with {@see self::setVerifier()} is used (the core package
 *    registers `strapi.auth.verify` there at boot); with nothing registered, every scope is allowed.
 *
 * Decisions are memoized per `$auth` value for the lifetime of the auth object, as upstream does.
 */
final class AuthScope
{
    /** @var \Closure(mixed, string): bool|null */
    private static ?\Closure $verifier = null;

    /** @var \Closure(): list<string>|null */
    private static ?\Closure $registeredContentTypes = null;

    /** @var \WeakMap<object, array<string, bool>> */
    private static ?\WeakMap $cache = null;

    /**
     * Register the process-wide verifier: `callable(mixed $auth, string $scope): bool` (may also throw to deny).
     *
     * @param callable(mixed, string): bool|null $verifier
     */
    public static function setVerifier(?callable $verifier): void
    {
        self::$verifier = $verifier === null ? null : $verifier(...);
        self::$cache = null;
    }

    /**
     * Register how to list every registered content type uid (upstream `Object.values(strapi.contentTypes)`).
     *
     * @param callable(): list<string>|null $provider
     */
    public static function setRegisteredContentTypes(?callable $provider): void
    {
        self::$registeredContentTypes = $provider === null ? null : $provider(...);
    }

    /** @return list<string> */
    public static function getRegisteredContentTypeUIDs(): array
    {
        return self::$registeredContentTypes === null ? [] : (self::$registeredContentTypes)();
    }

    public static function canAccessScope(string $scope, mixed $auth): bool
    {
        $cacheable = is_object($auth);
        if ($cacheable) {
            self::$cache ??= new \WeakMap();
            $decisions = self::$cache[$auth] ?? [];
            if (array_key_exists($scope, $decisions)) {
                return $decisions[$scope];
            }
        }

        $allowed = self::decide($scope, $auth);

        if ($cacheable && self::$cache !== null) {
            $decisions = self::$cache[$auth] ?? [];
            $decisions[$scope] = $allowed;
            self::$cache[$auth] = $decisions;
        }

        return $allowed;
    }

    private static function decide(string $scope, mixed $auth): bool
    {
        $ability = self::member($auth, 'ability');
        if (is_callable($ability)) {
            return self::run(static fn (): bool => (bool) $ability($scope, null, null));
        }
        if (is_object($ability) && method_exists($ability, 'can')) {
            return self::run(static fn (): bool => (bool) $ability->can($scope));
        }

        $verify = self::member($auth, 'verify');
        if (is_callable($verify)) {
            return self::run(static fn (): bool => $verify($scope) !== false);
        }

        if (self::$verifier !== null) {
            $verifier = self::$verifier;

            return self::run(static fn (): bool => $verifier($auth, $scope) !== false);
        }

        return true;
    }

    private static function member(mixed $auth, string $name): mixed
    {
        if (is_array($auth)) {
            return $auth[$name] ?? null;
        }
        if (is_object($auth)) {
            if (method_exists($auth, $name)) {
                return [$auth, $name];
            }
            if (property_exists($auth, $name) || isset($auth->{$name})) {
                return $auth->{$name};
            }
        }

        return null;
    }

    /** @param callable(): bool $check */
    private static function run(callable $check): bool
    {
        try {
            return $check();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<string> $scopes */
    public static function hasAccessToSomeScopes(array $scopes, mixed $auth): bool
    {
        foreach ($scopes as $scope) {
            if (self::canAccessScope($scope, $auth)) {
                return true;
            }
        }

        return false;
    }
}
