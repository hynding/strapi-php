<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Strapi\CreateStrapiApp\Types;

/**
 * Port of packages/cli/create-strapi-app/src/utils/dot-env.ts.
 *
 * Same template and same secrets: 16 random bytes in base64 each (`crypto.randomBytes(16)
 * .toString('base64')`), four of them comma-separated for APP_KEYS. lodash's `<%= value %>`
 * prints null/undefined as an empty string and booleans as `true`/`false`.
 *
 * @phpstan-import-type Scope from Types
 */
final class DotEnv
{
    private const ENV_TMPL = <<<'ENV'

        # Server
        HOST=0.0.0.0
        PORT=1337

        # Secrets
        APP_KEYS=<%= appKeys %>
        API_TOKEN_SALT=<%= apiTokenSalt %>
        ADMIN_JWT_SECRET=<%= adminJwtToken %>
        JWT_SECRET=<%= jwtSecret %>
        TRANSFER_TOKEN_SALT=<%= transferTokenSalt %>
        ENCRYPTION_KEY=<%= encryptionKey %>

        # Database
        DATABASE_CLIENT=<%= database.client %>
        DATABASE_HOST=<%= database.connection.host %>
        DATABASE_PORT=<%= database.connection.port %>
        DATABASE_NAME=<%= database.connection.database %>
        DATABASE_USERNAME=<%= database.connection.username %>
        DATABASE_PASSWORD=<%= database.connection.password %>
        DATABASE_SSL=<%= database.connection.ssl %>
        DATABASE_FILENAME=<%= database.connection.filename %>

        ENV;

    public static function generateASecret(): string
    {
        return base64_encode(random_bytes(16));
    }

    /** @param Scope $scope */
    public static function generateDotEnv(array $scope): string
    {
        $connection = $scope['database']['connection'];

        $values = [
            'appKeys' => implode(',', array_map(static fn (): string => self::generateASecret(), range(1, 4))),
            'apiTokenSalt' => self::generateASecret(),
            'transferTokenSalt' => self::generateASecret(),
            'adminJwtToken' => self::generateASecret(),
            'jwtSecret' => self::generateASecret(),
            'encryptionKey' => self::generateASecret(),
            'database.client' => $scope['database']['client'],
            'database.connection.host' => $connection['host'] ?? null,
            'database.connection.port' => $connection['port'] ?? null,
            'database.connection.database' => $connection['database'] ?? null,
            'database.connection.username' => $connection['username'] ?? null,
            'database.connection.password' => $connection['password'] ?? null,
            'database.connection.ssl' => ($connection['ssl'] ?? false) ?: false,
            'database.connection.filename' => $connection['filename'] ?? null,
        ];

        return (string) preg_replace_callback(
            '/<%=\s*([\w.]+)\s*%>/',
            static function (array $m) use ($values): string {
                $value = $values[$m[1]] ?? null;

                return match (true) {
                    $value === null => '',
                    is_bool($value) => $value ? 'true' : 'false',
                    default => (string) $value,
                };
            },
            self::ENV_TMPL,
        );
    }
}
