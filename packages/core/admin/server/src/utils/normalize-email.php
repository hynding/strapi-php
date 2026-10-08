<?php

declare(strict_types=1);

namespace Strapi\Admin\Utils;

/** Port of server/src/utils/normalize-email.ts. */
final class NormalizeEmail
{
    /**
     * Returns a shallow copy of an admin user payload with `email` lowercased.
     *
     * Admin email uniqueness depends on values being stored canonically lowercase: `admin_users.email`
     * is a plain unique index with no guaranteed case-insensitive collation across SQLite/Postgres/MySQL,
     * and the strict Yup email validator rejects mixed-case input rather than normalizing it. Controllers
     * must therefore lowercase the email before validation, the uniqueness check, and persistence
     * (mirroring `user.create`). The `email` key is only rewritten when present, so partial updates that
     * omit it are left untouched.
     */
    public static function normalizeEmail(mixed $payload): mixed
    {
        if (is_array($payload) && is_string($payload['email'] ?? null)) {
            return [...$payload, 'email' => strtolower($payload['email'])];
        }

        return $payload;
    }
}
