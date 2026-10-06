<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Attributes;

/** Port of attributes/transforms.ts: per-type input transforms (password hashing with bcrypt). */
final class Transforms
{
    /**
     * @param array{attribute: array<string, mixed>, attributeName: string} $context
     */
    public static function password(mixed $value, array $context): mixed
    {
        $attribute = $context['attribute'];

        if (($attribute['type'] ?? null) !== 'password') {
            throw new \RuntimeException('Invalid attribute type');
        }

        if (!is_string($value)) {
            return $value;
        }

        $rounds = (int) ($attribute['encryption']['rounds'] ?? 10);

        return password_hash($value, PASSWORD_BCRYPT, ['cost' => max(4, min(31, $rounds))]);
    }

    /** @return callable(mixed, array{attribute: array<string, mixed>, attributeName: string}): mixed|null */
    public static function for(string $type): ?callable
    {
        return match ($type) {
            'password' => self::password(...),
            default => null,
        };
    }
}
