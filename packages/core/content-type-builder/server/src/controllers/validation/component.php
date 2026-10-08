<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers\Validation;

use Strapi\ContentTypeBuilder\Services\Constants;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupArray;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/component.ts. */
final class Component
{
    public const array VALID_RELATIONS = ['oneToOne', 'oneToMany'];

    /** @return list<string> */
    public static function validTypes(): array
    {
        return [...Constants::DEFAULT_TYPES, 'component', 'customField'];
    }

    public static function componentSchema(): YupObject
    {
        return ModelSchema::createSchema(self::validTypes(), self::VALID_RELATIONS, [
            'modelType' => Constants::MODEL_TYPES['COMPONENT'],
        ])
            ->shape([
                'displayName' => Yup::string()->min(1)->required('displayName.required'),
                'icon' => Yup::string()->nullable()->test(Common::isValidIcon()),
                'category' => Yup::string()->nullable()->test(Common::isValidCategoryName())->required('category.required'),
            ])
            ->required()
            ->noUnknown();
    }

    public static function nestedComponentSchema(): YupArray
    {
        return Yup::array()->of(
            self::componentSchema()
                ->shape([
                    'uid' => Yup::string(),
                    'tmpUID' => Yup::string(),
                ])
                ->test([
                    'name' => 'mustHaveUIDOrTmpUID',
                    'message' => 'Component must have a uid or a tmpUID',
                    'test' => static function (mixed $attr): bool {
                        $hasUid = is_array($attr) && array_key_exists('uid', $attr);
                        $hasTmpUid = is_array($attr) && array_key_exists('tmpUID', $attr);

                        if ($hasUid && $hasTmpUid) {
                            return false;
                        }
                        if (!$hasUid && !$hasTmpUid) {
                            return false;
                        }

                        return true;
                    },
                ])
                ->required()
                ->noUnknown(),
        );
    }

    public static function componentInputSchema(): YupObject
    {
        return Yup::object([
            'component' => self::componentSchema(),
            'components' => self::nestedComponentSchema(),
        ])->noUnknown();
    }

    public static function validateComponentInput(mixed $data): mixed
    {
        return Validators::validateYupSchema(self::componentInputSchema())($data);
    }

    /**
     * Upstream strips empty defaults from the payload in place before validating; the cleaned
     * payload is returned here.
     */
    public static function validateUpdateComponentInput(mixed $data): mixed
    {
        if (is_array($data)) {
            if (is_array($data['component'] ?? null)) {
                $data['component'] = DataTransform::removeEmptyDefaults($data['component']);
            }

            if (is_array($data['components'] ?? null) && array_is_list($data['components'])) {
                foreach ($data['components'] as $i => $component) {
                    if (is_array($component) && array_key_exists('uid', $component)) {
                        $data['components'][$i] = DataTransform::removeEmptyDefaults($component);
                    }
                }
            }
        }

        // updateComponentInputSchema is the same shape as componentInputSchema
        Validators::validateYupSchema(self::componentInputSchema())($data);

        return $data;
    }
}
