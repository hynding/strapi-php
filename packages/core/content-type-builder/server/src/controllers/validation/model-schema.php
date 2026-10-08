<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers\Validation;

use Strapi\ContentTypeBuilder\Services\Builder;
use Strapi\ContentTypeBuilder\Services\Constants;
use Strapi\Utils\Primitives\Strings;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\Yup as YupSchema;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/model-schema.ts. */
final class ModelSchema
{
    /**
     * @param list<string> $types
     * @param list<string> $relations
     * @param array{modelType?: string|null} $options
     */
    public static function createSchema(array $types, array $relations, array $options = []): YupObject
    {
        $modelType = $options['modelType'] ?? null;

        $shape = [
            'description' => Yup::string(),
            'options' => Yup::object(),
            'pluginOptions' => Yup::object(),
            'collectionName' => Yup::string()->nullable()->test(Common::isValidCollectionName()),
            'attributes' => self::createAttributesValidator($types, $relations, $modelType),
            'draftAndPublish' => Yup::boolean(),
        ];

        if ($modelType === Constants::MODEL_TYPES['CONTENT_TYPE']) {
            $shape['kind'] = Yup::string()->oneOf([Constants::TYPE_KINDS['SINGLE_TYPE'], Constants::TYPE_KINDS['COLLECTION_TYPE']])->nullable();
        }

        return Yup::object($shape)->noUnknown();
    }

    /**
     * @param list<string> $types
     * @param list<string> $relations
     */
    private static function createAttributesValidator(array $types, array $relations, ?string $modelType): YupSchema
    {
        return Yup::lazy(static function (mixed $attributes) use ($types, $relations, $modelType): YupSchema {
            $attributes = is_array($attributes) ? $attributes : [];
            $shape = [];

            foreach ($attributes as $key => $attribute) {
                $key = (string) $key;
                $attribute = is_array($attribute) ? $attribute : [];

                if (self::isForbiddenKey($key)) {
                    $shape[$key] = self::forbiddenValidator();
                    continue;
                }

                if (self::isConflictingKey($key, $attributes)) {
                    $shape[$key] = self::conflictingKeysValidator($key);
                    continue;
                }

                if (($attribute['type'] ?? null) === 'relation') {
                    $shape[$key] = Relations::getRelationValidator($attribute, $relations)->test(Common::isValidKey($key));
                    continue;
                }

                if (array_key_exists('type', $attribute)) {
                    $shape[$key] = Types::getTypeValidator($attribute, ['types' => $types, 'modelType' => $modelType, 'attributes' => $attributes])
                        ->test(Common::isValidKey($key));
                    continue;
                }

                $shape[$key] = self::typeOrRelationValidator();
            }

            return Yup::object()->shape($shape)->required('attributes.required');
        });
    }

    /** @param array<array-key, mixed> $attributes */
    private static function isConflictingKey(string $key, array $attributes): bool
    {
        $snakeCaseKey = Strings::snakeCase($key);

        foreach (array_keys($attributes) as $existingKey) {
            $existingKey = (string) $existingKey;
            if ($existingKey === $key) {
                continue; // don't compare against itself
            }
            if (Strings::snakeCase($existingKey) === $snakeCaseKey) {
                return true;
            }
        }

        return false;
    }

    private static function isForbiddenKey(string $key): bool
    {
        return Builder::isReservedAttributeName($key);
    }

    private static function forbiddenValidator(): YupSchema
    {
        $reservedNames = Builder::getReservedNames()['attributes'];

        return Yup::mixed()->test([
            'name' => 'forbiddenKeys',
            'message' => 'Attribute keys cannot be one of ' . implode(', ', $reservedNames),
            'test' => static fn (): bool => false,
        ]);
    }

    private static function conflictingKeysValidator(string $key): YupSchema
    {
        return Yup::mixed()->test([
            'name' => 'conflictingKeys',
            'message' => "Attribute {$key} conflicts with an existing key",
            'test' => static fn (): bool => false,
        ]);
    }

    private static function typeOrRelationValidator(): YupSchema
    {
        return Yup::object()->test([
            'name' => 'mustHaveTypeOrTarget',
            'message' => 'Attribute must have either a type or a target',
            'test' => static fn (): bool => false,
        ]);
    }
}
