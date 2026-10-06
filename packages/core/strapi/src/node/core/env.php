<?php

declare(strict_types=1);

namespace Strapi\Cli\Node\Core;

/** Port of packages/core/strapi/src/node/core/env.ts (`loadEnv`, `getStrapiAdminEnvVars`). */
final class Env
{
    /** Load the `.env` file if it exists (does not override variables already set). */
    public static function loadEnv(string $cwd): void
    {
        if (!is_file($cwd . '/.env')) {
            return;
        }
        \Dotenv\Dotenv::createImmutable($cwd)->safeLoad();
    }

    /**
     * All the environment variables that start with `STRAPI_ADMIN_`, on top of the defaults.
     *
     * @param array<string, string> $defaultEnv
     * @return array<string, string>
     */
    public static function getStrapiAdminEnvVars(array $defaultEnv): array
    {
        $env = $defaultEnv;
        foreach ([...getenv(), ...$_ENV] as $key => $value) {
            if (is_string($key) && is_string($value) && str_starts_with(strtoupper($key), 'STRAPI_ADMIN_')) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
