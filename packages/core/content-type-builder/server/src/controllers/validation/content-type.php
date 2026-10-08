<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers\Validation;

use Strapi\ContentTypeBuilder\Services\Builder;
use Strapi\ContentTypeBuilder\Services\Constants;
use Strapi\Core\Core;
use Strapi\Utils\Primitives\Strings;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/**
 * Port of server/src/controllers/validation/content-type.ts.
 *
 * @phpstan-type CreateContentTypeInput array<string, mixed>
 */
final class ContentType
{
    /** Allowed relation per type kind */
    private const array VALID_RELATIONS = [
        'singleType' => [
            'oneToOne',
            'oneToMany',
            'morphOne',
            'morphMany',
            'morphToOne',
            'morphToMany',
        ],
        'collectionType' => [
            'oneToOne',
            'oneToMany',
            'manyToOne',
            'manyToMany',
            'morphOne',
            'morphMany',
            'morphToOne',
            'morphToMany',
        ],
    ];

    /** @return list<string> Allowed types */
    public static function validTypes(): array
    {
        return [...Constants::DEFAULT_TYPES, 'uid', 'component', 'dynamiczone', 'customField'];
    }

    /**
     * Returns a yup schema to validate a content type payload.
     *
     * @param array{isEdition?: bool} $options
     */
    private static function createContentTypeSchema(mixed $data, array $options = []): YupObject
    {
        $isEdition = $options['isEdition'] ?? false;

        $kind = is_array($data) && is_array($data['contentType'] ?? null) && array_key_exists('kind', $data['contentType'])
            ? $data['contentType']['kind']
            : Constants::TYPE_KINDS['COLLECTION_TYPE'];

        $contentTypeSchema = ModelSchema::createSchema(
            self::validTypes(),
            is_string($kind) ? (self::VALID_RELATIONS[$kind] ?? []) : [],
            ['modelType' => Constants::MODEL_TYPES['CONTENT_TYPE']],
        )
            ->shape([
                'displayName' => Yup::string()->min(1)->required(),
                'singularName' => Yup::string()
                    ->min(1)
                    ->test(self::nameIsAvailable($isEdition))
                    ->test(self::forbiddenContentTypeNameValidator())
                    ->isKebabCase()
                    ->required(),
                'pluralName' => Yup::string()
                    ->min(1)
                    ->test(self::nameIsAvailable($isEdition))
                    ->test(self::nameIsNotExistingCollectionName($isEdition)) // TODO: v5: require singularName to not match a collection name
                    ->test(self::forbiddenContentTypeNameValidator())
                    ->isKebabCase()
                    ->required(),
            ])
            ->test(
                'singularName-not-equal-pluralName',
                '${path}: singularName and pluralName should be different',
                static function (mixed $value): bool {
                    $value = is_array($value) ? $value : [];

                    return ($value['singularName'] ?? null) !== ($value['pluralName'] ?? null);
                },
            );

        return Yup::object([
            // FIXME .noUnknown(false) will strip off the unwanted properties without throwing an error
            // Why not having .noUnknown() ? Because we want to be able to add options relatable to EE features
            // without having any reference to them in CE.
            // Why not handle an "options" object in the content-type ? The admin panel needs lots of rework
            // to be able to send this options object instead of top-level attributes.
            // @nathan-pichon 20/02/2023
            'contentType' => $contentTypeSchema->required()->noUnknown(false),
            'components' => Component::nestedComponentSchema(),
        ])->noUnknown();
    }

    /** Validator for content type creation. */
    public static function validateContentTypeInput(mixed $data): mixed
    {
        return Validators::validateYupSchema(self::createContentTypeSchema($data))($data);
    }

    /**
     * Validator for content type edition. Upstream cleans the payload in place before validating;
     * the cleaned payload is returned here.
     */
    public static function validateUpdateContentTypeInput(mixed $data): mixed
    {
        if (is_array($data) && array_key_exists('contentType', $data) && is_array($data['contentType'])) {
            $data['contentType'] = DataTransform::removeDeletedUIDTargetFields(DataTransform::removeEmptyDefaults($data['contentType']) ?? []);
        }

        if (is_array($data) && is_array($data['components'] ?? null) && array_is_list($data['components'])) {
            foreach ($data['components'] as $i => $comp) {
                if (is_array($comp) && array_key_exists('uid', $comp)) {
                    $data['components'][$i] = DataTransform::removeEmptyDefaults($comp);
                }
            }
        }

        Validators::validateYupSchema(self::createContentTypeSchema($data, ['isEdition' => true]))($data);

        return $data;
    }

    /** @return array{name: string, message: string, test: \Closure(mixed): bool} */
    private static function forbiddenContentTypeNameValidator(): array
    {
        $reservedNames = Builder::getReservedNames()['models'];

        return [
            'name' => 'forbiddenContentTypeName',
            'message' => 'Content Type name cannot be one of ' . implode(', ', $reservedNames),
            'test' => static function (mixed $value): bool {
                if (!is_string($value)) {
                    return true;
                }

                return !Builder::isReservedModelName($value);
            },
        ];
    }

    /** @return array{name: string, message: string, test: \Closure(mixed): bool} */
    private static function nameIsAvailable(bool $isEdition): array
    {
        $usedNames = [];
        foreach (Core::instance()?->contentTypes() ?? [] as $ct) {
            $usedNames[] = $ct->info['singularName'] ?? null;
            $usedNames[] = $ct->info['pluralName'] ?? null;
        }

        return [
            'name' => 'nameAlreadyUsed',
            'message' => 'contentType: name `${value}` is already being used by another content type.',
            'test' => static function (mixed $value) use ($isEdition, $usedNames): bool {
                // don't check on edition
                if ($isEdition) {
                    return true;
                }

                // ignore if not a string (will be caught in another validator)
                if (!is_string($value)) {
                    return true;
                }

                // compare snake case to check the actual column names that will be used in the database
                foreach ($usedNames as $usedName) {
                    if (Strings::snakeCase(is_string($usedName) ? $usedName : '') === Strings::snakeCase($value)) {
                        return false;
                    }
                }

                return true;
            },
        ];
    }

    /** @return array{name: string, message: string, test: \Closure(mixed): bool} */
    private static function nameIsNotExistingCollectionName(bool $isEdition): array
    {
        $usedNames = [];
        foreach (Core::instance()?->contentTypes() ?? [] as $ct) {
            $usedNames[] = $ct->collectionName;
        }

        return [
            'name' => 'nameAlreadyUsed',
            'message' => 'contentType: name `${value}` is already being used by another content type.',
            'test' => static function (mixed $value) use ($isEdition, $usedNames): bool {
                // don't check on edition
                if ($isEdition) {
                    return true;
                }

                // ignore if not a string (will be caught in another validator)
                if (!is_string($value)) {
                    return true;
                }

                // compare snake case to check the actual column names that will be used in the database
                foreach ($usedNames as $usedName) {
                    if (Strings::snakeCase($usedName) === Strings::snakeCase($value)) {
                        return false;
                    }
                }

                return true;
            },
        ];
    }

    /** Validates type kind. */
    public static function validateKind(mixed $kind): mixed
    {
        $kindSchema = Yup::string()->oneOf([Constants::TYPE_KINDS['SINGLE_TYPE'], Constants::TYPE_KINDS['COLLECTION_TYPE']]);

        $validated = Validators::validateYupSchema($kindSchema)($kind ?? Yup::undefined());

        return $validated instanceof \Strapi\Utils\Yup\Undefined ? null : $validated;
    }
}
