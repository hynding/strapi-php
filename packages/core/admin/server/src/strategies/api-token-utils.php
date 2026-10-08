<?php

declare(strict_types=1);

namespace Strapi\Admin\Strategies;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\UnauthorizedError;

/** Port of server/src/strategies/api-token-utils.ts. */
final class ApiTokenUtils
{
    public static function extractToken(Context $ctx): ?string
    {
        $authorization = $ctx->header('Authorization');

        if ($authorization !== null && $authorization !== '') {
            $parts = preg_split('/\s+/', $authorization) ?: [];

            if (strtolower($parts[0] ?? '') !== 'bearer' || count($parts) !== 2) {
                return null;
            }

            return $parts[1];
        }

        return null;
    }

    /** `new Date(value)` for a stored date: an ISO string, epoch milliseconds or a date. */
    public static function toDate(mixed $value): ?\DateTimeImmutable
    {
        try {
            if ($value instanceof \DateTimeInterface) {
                return \DateTimeImmutable::createFromInterface($value);
            }
            if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
                $ms = (int) $value;

                return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->modify('+' . ($ms % 1000) . ' milliseconds') ?: null;
            }
            if (is_string($value)) {
                return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            }
        } catch (\Exception) {
            return null;
        }

        return null;
    }

    /** @param array<string, mixed> $apiToken */
    public static function checkExpiry(array $apiToken): ?UnauthorizedError
    {
        if (($apiToken['expiresAt'] ?? null) !== null) {
            $expirationDate = self::toDate($apiToken['expiresAt']);
            // `new Date('garbage') < new Date()` is false: an unparsable date never expires
            if ($expirationDate !== null && $expirationDate < new \DateTimeImmutable()) {
                return new UnauthorizedError('Token expired');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $apiToken */
    public static function updateLastUsedAt(Strapi $strapi, array $apiToken): void
    {
        $currentDate = new \DateTimeImmutable();

        if (($apiToken['lastUsedAt'] ?? null) !== null) {
            $lastUsedAt = self::toDate($apiToken['lastUsedAt']);
            // date-fns differenceInHours truncates toward zero
            $hoursSinceLastUsed = $lastUsedAt === null
                ? null
                : (int) (($currentDate->getTimestamp() - $lastUsedAt->getTimestamp()) / 3600);
            if ($hoursSinceLastUsed !== null && $hoursSinceLastUsed >= 1) {
                $strapi->db()->query('admin::api-token')->update([
                    'where' => ['id' => $apiToken['id'] ?? null],
                    'data' => ['lastUsedAt' => $currentDate],
                ]);
            }
        } else {
            $strapi->db()->query('admin::api-token')->update([
                'where' => ['id' => $apiToken['id'] ?? null],
                'data' => ['lastUsedAt' => $currentDate],
            ]);
        }
    }
}
