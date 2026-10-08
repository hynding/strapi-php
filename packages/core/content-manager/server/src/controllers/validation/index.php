<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers\Validation;

use Strapi\ContentManager\Validation\Zod;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\PaginationError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Yup\YupObject;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodType;

/** Port of server/src/controllers/validation/index.ts. */
final class Validation
{
    private const array TYPES = ['singleType', 'collectionType'];

    /**
     * Validates type kind
     */
    private static function kindSchema(): ZodType
    {
        return z::enum(self::TYPES)->nullable()->optional();
    }

    private static function bulkActionInputSchema(): ZodType
    {
        return z::object([
            'documentIds' => z::array(Zod::strapiID())->min(1),
        ]);
    }

    private static function generateUIDInputSchema(): ZodType
    {
        return z::object([
            'contentTypeUID' => z::string(),
            'field' => z::string(),
            'data' => z::looseObject([]),
        ]);
    }

    private static function createCheckUIDAvailabilityInputSchema(?string $regex = null): ZodType
    {
        $pattern = $regex !== null && $regex !== '' ? self::toPcre($regex) : '/^[A-Za-z0-9-_.~]*$/';

        return z::object([
            'contentTypeUID' => z::string(),
            'field' => z::string(),
            'value' => z::string()->refine(
                static fn (mixed $value): bool => $value === '' || (is_string($value) && preg_match($pattern, $value) === 1),
                ['error' => 'Must match the custom regex or the default one "/^[A-Za-z0-9-_.~]*$/"'],
            ),
        ]);
    }

    /** `new RegExp(source)` as a PCRE pattern. */
    private static function toPcre(string $source): string
    {
        return '/' . str_replace('/', '\/', $source) . '/u';
    }

    /**
     * Creates the validation schema for content-type configurations
     * (re-exported from ./model-configuration).
     *
     * @param array<string, mixed> $schema
     * @param array{allowUndefined?: bool} $opts
     */
    public static function createModelConfigurationSchema(Strapi $strapi, array $schema, array $opts = []): YupObject
    {
        return ModelConfiguration::createModelConfigurationSchema($strapi, $schema, $opts);
    }

    public static function validateUIDField(Strapi $strapi, mixed $contentTypeUID, mixed $field): void
    {
        $model = is_string($contentTypeUID) ? ($strapi->contentTypes()[$contentTypeUID] ?? null) : null;

        if ($model === null) {
            throw new ValidationError('ContentType not found');
        }

        $fieldName = is_string($field) || is_int($field) ? (string) $field : '';
        if (
            !array_key_exists($fieldName, $model->attributes)
            || ($model->attributes[$fieldName]['type'] ?? null) !== 'uid'
        ) {
            throw new ValidationError("{$fieldName} must be a valid `uid` attribute");
        }
    }

    /** @param array{page?: mixed, pageSize?: mixed} $params */
    public static function validatePagination(array $params): void
    {
        $pageNumber = self::parseInt($params['page'] ?? null);
        $pageSizeNumber = self::parseInt($params['pageSize'] ?? null);

        if ($pageNumber === null || $pageNumber < 1) {
            throw new PaginationError('invalid pageNumber param');
        }
        if ($pageSizeNumber === null || $pageSizeNumber < 1) {
            throw new PaginationError('invalid pageSize param');
        }
    }

    /** `parseInt(value, 10)`; null for NaN. */
    private static function parseInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\s*([+-]?\d+)/', $value, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    public static function validateKind(mixed $kind, ?string $errorMessage = null): mixed
    {
        return Zod::validateZodAsync(self::kindSchema())($kind, $errorMessage);
    }

    public static function validateBulkActionInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Zod::validateZodAsync(self::bulkActionInputSchema())($body, $errorMessage);
    }

    /** @return array{contentTypeUID: string, field: string, data: array<string, mixed>} */
    public static function validateGenerateUIDInput(mixed $body, ?string $errorMessage = null): array
    {
        /** @var array{contentTypeUID: string, field: string, data: array<string, mixed>} */
        return Zod::validateZodAsync(self::generateUIDInputSchema())($body, $errorMessage);
    }

    /** @return array{contentTypeUID: string, field: string, value: string} */
    public static function validateCheckUIDAvailabilityInput(Strapi $strapi, mixed $body): array
    {
        $regex = null;

        $contentTypeUID = is_array($body) ? ($body['contentTypeUID'] ?? null) : null;
        $field = is_array($body) ? ($body['field'] ?? null) : null;
        $contentType = is_string($contentTypeUID) ? ($strapi->contentTypes()[$contentTypeUID] ?? null) : null;

        if ($contentType !== null && (is_string($field) || is_int($field))) {
            $attribute = $contentType->attributes[$field] ?? null;
            if (is_array($attribute) && array_key_exists('regex', $attribute) && is_string($attribute['regex']) && $attribute['regex'] !== '') {
                $regex = $attribute['regex'];
            }
        }

        $schema = self::createCheckUIDAvailabilityInputSchema($regex);

        /** @var array{contentTypeUID: string, field: string, value: string} */
        return Zod::validateZodAsync($schema)($body);
    }
}
