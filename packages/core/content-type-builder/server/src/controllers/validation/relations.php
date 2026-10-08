<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers\Validation;

use Strapi\ContentTypeBuilder\Services\Constants;
use Strapi\Core\Core;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\TestContext;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\YupError;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/relations.ts. */
final class Relations
{
    private const array STRAPI_USER_RELATIONS = ['oneToOne', 'oneToMany'];

    /**
     * @param list<string> $validNatures
     * @return \Closure(mixed, TestContext): (bool|YupError)
     */
    private static function isValidRelation(array $validNatures): \Closure
    {
        return static function (mixed $value, TestContext $ctx) use ($validNatures): bool|YupError {
            // NOTE: In case of an undefined value, delegate the check to .required()
            if ($value instanceof Undefined) {
                return true;
            }

            $parent = is_array($ctx->parent) ? $ctx->parent : [];

            if (($parent['target'] ?? null) === Constants::CORE_UIDS['STRAPI_USER']) {
                if (!in_array($value, $validNatures, true) || array_key_exists('targetAttribute', $parent)) {
                    return $ctx->createError([
                        'path' => $ctx->path,
                        'message' => 'must be one of the following values: ' . implode(', ', self::STRAPI_USER_RELATIONS),
                    ]);
                }
            }

            return in_array($value, $validNatures, true)
                ? true
                : $ctx->createError([
                    'path' => $ctx->path,
                    'message' => 'must be one of the following values: ' . implode(', ', $validNatures),
                ]);
        };
    }

    /**
     * @param array<string, mixed> $attribute
     * @param list<string> $allowedRelations
     */
    public static function getRelationValidator(array $attribute, array $allowedRelations): YupObject
    {
        $contentTypesUIDs = [];
        foreach (Core::instance()?->contentTypes() ?? [] as $key => $contentType) {
            $key = (string) $key;
            if ($contentType->kind !== Constants::TYPE_KINDS['COLLECTION_TYPE']) {
                continue;
            }
            if (str_starts_with($key, Constants::CORE_UIDS['PREFIX']) && $key !== Constants::CORE_UIDS['STRAPI_USER']) {
                continue;
            }
            $contentTypesUIDs[] = $key;
        }
        $contentTypesUIDs = [...$contentTypesUIDs, '__self__', '__contentType__'];

        $base = [
            'type' => Yup::string()->oneOf(['relation'])->required(),
            'relation' => Yup::string()->test('isValidRelation', self::isValidRelation($allowedRelations))->required(),
            'configurable' => Yup::boolean()->nullable(),
            'required' => Yup::boolean(),
            'private' => Yup::boolean()->nullable(),
            'pluginOptions' => Yup::object(),
        ];

        switch ($attribute['relation'] ?? null) {
            case 'oneToOne':
            case 'oneToMany':
            case 'manyToOne':
            case 'manyToMany':
            case 'morphOne':
            case 'morphMany':
                return Yup::object([
                    ...$base,
                    'target' => Yup::string()->oneOf($contentTypesUIDs)->required(),
                    'targetAttribute' => Yup::string()->test(Common::isValidName())->nullable(),
                ]);
            case 'morphToOne':
            case 'morphToMany':
            default:
                return Yup::object($base);
        }
    }
}
