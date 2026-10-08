<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/field-sizes.ts.
 *
 * Upstream's `fieldSizes` map is module state (shared by every service instance); here it is a
 * static property for the same reason.
 *
 * @phpstan-type FieldSize array{default: int, isResizable: bool}
 */
final class FieldSizes
{
    private const array NEEDS_FULL_SIZE = ['default' => 12, 'isResizable' => false];
    private const array SMALL_SIZE = ['default' => 4, 'isResizable' => true];
    private const array DEFAULT_SIZE = ['default' => 6, 'isResizable' => true];

    /** @var array<string, FieldSize> */
    public const array FIELD_SIZES = [
        // Full row and not resizable
        'dynamiczone' => self::NEEDS_FULL_SIZE,
        'component' => self::NEEDS_FULL_SIZE,
        'json' => self::NEEDS_FULL_SIZE,
        'richtext' => self::NEEDS_FULL_SIZE,
        'blocks' => self::NEEDS_FULL_SIZE,
        // Small and resizable
        'checkbox' => self::SMALL_SIZE,
        'boolean' => self::SMALL_SIZE,
        'date' => self::SMALL_SIZE,
        'time' => self::SMALL_SIZE,
        'biginteger' => self::SMALL_SIZE,
        'decimal' => self::SMALL_SIZE,
        'float' => self::SMALL_SIZE,
        'integer' => self::SMALL_SIZE,
        'number' => self::SMALL_SIZE,
        // Medium and resizable
        'datetime' => self::DEFAULT_SIZE,
        'email' => self::DEFAULT_SIZE,
        'enumeration' => self::DEFAULT_SIZE,
        'media' => self::DEFAULT_SIZE,
        'password' => self::DEFAULT_SIZE,
        'relation' => self::DEFAULT_SIZE,
        'string' => self::DEFAULT_SIZE,
        'text' => self::DEFAULT_SIZE,
        'timestamp' => self::DEFAULT_SIZE,
        'uid' => self::DEFAULT_SIZE,
    ];

    /** @var array<string, FieldSize> */
    private static array $fieldSizes = self::FIELD_SIZES;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array<string, FieldSize> */
    public function getAllFieldSizes(): array
    {
        return self::$fieldSizes;
    }

    public function hasFieldSize(?string $type): bool
    {
        return $type !== null && isset(self::$fieldSizes[$type]);
    }

    /** @return FieldSize */
    public function getFieldSize(?string $type = null): array
    {
        if ($type === null || $type === '') {
            throw new ApplicationError('The type is required');
        }

        $fieldSize = self::$fieldSizes[$type] ?? null;
        if ($fieldSize === null) {
            throw new ApplicationError("Could not find field size for type {$type}");
        }

        return $fieldSize;
    }

    /** @param FieldSize|null $size */
    public function setFieldSize(?string $type, ?array $size): void
    {
        if ($type === null || $type === '') {
            throw new ApplicationError('The type is required');
        }

        if ($size === null || $size === []) {
            throw new ApplicationError('The size is required');
        }

        self::$fieldSizes[$type] = $size;
    }

    public function setCustomFieldInputSizes(): void
    {
        // Find all custom fields already registered
        $customFields = $this->strapi->get('custom-fields')->getAll();

        // If they have a custom field size, register it
        foreach ($customFields as $uid => $customField) {
            $inputSize = is_array($customField) ? ($customField['inputSize'] ?? null) : null;
            if (is_array($inputSize) && $inputSize !== []) {
                /** @var FieldSize $inputSize */
                $this->setFieldSize((string) $uid, $inputSize);
            }
        }
    }

    /** Restores the built-in sizes (tests; upstream re-imports the module). */
    public static function reset(): void
    {
        self::$fieldSizes = self::FIELD_SIZES;
    }
}
