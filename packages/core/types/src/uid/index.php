<?php

declare(strict_types=1);

namespace Strapi\Types\Uid;

/**
 * UID helpers. Strapi identifies everything by a namespaced UID:
 *   api::article.article   plugin::users-permissions.user   admin::user
 *   strapi::cors (middleware)   global::is-authenticated (policy)   default.seo (component)
 *
 * Mirrors packages/core/types/src/uid and the parsing helpers in @strapi/utils.
 */
final class Uid
{
    public const API = 'api';
    public const PLUGIN = 'plugin';
    public const ADMIN = 'admin';
    public const STRAPI = 'strapi';
    public const GLOBAL = 'global';

    /**
     * @return array{namespace: string, origin: string|null, name: string}
     */
    public static function parse(string $uid): array
    {
        if (preg_match('/^(api|plugin)::([a-z0-9-_]+)\.([a-z0-9-_]+)$/i', $uid, $m)) {
            return ['namespace' => strtolower($m[1]), 'origin' => $m[2], 'name' => $m[3]];
        }
        if (preg_match('/^(admin|strapi|global)::([a-z0-9-_.]+)$/i', $uid, $m)) {
            return ['namespace' => strtolower($m[1]), 'origin' => null, 'name' => $m[2]];
        }
        if (preg_match('/^([a-z0-9-_]+)\.([a-z0-9-_]+)$/i', $uid, $m)) {
            // component uid: <category>.<name>
            return ['namespace' => 'component', 'origin' => $m[1], 'name' => $m[2]];
        }

        throw new \InvalidArgumentException(sprintf('Invalid uid "%s"', $uid));
    }

    public static function isContentTypeUid(string $uid): bool
    {
        return (bool) preg_match('/^(api|plugin)::[a-z0-9-_]+\.[a-z0-9-_]+$|^admin::[a-z0-9-_]+$|^strapi::[a-z0-9-_]+$/i', $uid);
    }

    public static function isComponentUid(string $uid): bool
    {
        return (bool) preg_match('/^[a-z0-9-_]+\.[a-z0-9-_]+$/i', $uid);
    }

    public static function contentType(string $namespace, string $origin, string $name): string
    {
        return "{$namespace}::{$origin}.{$name}";
    }
}
