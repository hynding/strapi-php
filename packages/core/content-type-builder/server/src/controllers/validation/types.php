<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers\Validation;

use Strapi\ContentTypeBuilder\Services\Constants;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\TestContext;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\Yup as YupSchema;
use Strapi\Utils\Yup\YupObject;
use Strapi\Utils\Yup\YupString;

/** Port of server/src/controllers/validation/types.ts. */
final class Types
{
    /** @return array{name: string, message: string, test: \Closure(mixed, TestContext): bool} */
    private static function maxLengthIsGreaterThanOrEqualToMinLength(): array
    {
        return [
            'name' => 'isGreaterThanMin',
            'message' => 'maxLength must be greater or equal to minLength',
            'test' => static function (mixed $value, TestContext $ctx): bool {
                $minLength = is_array($ctx->parent) && array_key_exists('minLength', $ctx->parent) ? $ctx->parent['minLength'] : Undefined::value();

                return !(!$minLength instanceof Undefined && !$value instanceof Undefined && $value < $minLength);
            },
        ];
    }

    /**
     * @param array<string, mixed> $attribute
     * @param array{types: list<string>, modelType?: string|null, attributes?: array<string, mixed>|null} $options
     */
    public static function getTypeValidator(array $attribute, array $options): YupObject
    {
        return Yup::object([
            'type' => Yup::string()->oneOf($options['types'])->required(),
            'configurable' => Yup::boolean()->nullable(),
            'private' => Yup::boolean()->nullable(),
            'pluginOptions' => Yup::object(),
            ...self::getTypeShape($attribute, ['attributes' => $options['attributes'] ?? []]),
        ]);
    }

    /**
     * @param array<string, mixed> $attribute
     * @param array{attributes?: array<string, mixed>} $options
     * @return array<string, YupSchema>
     */
    private static function getTypeShape(array $attribute, array $options = []): array
    {
        $validators = Common::validators();
        $attributes = $options['attributes'] ?? [];

        switch ($attribute['type'] ?? null) {
            /*
             * complex types
             */

            case 'media':
                return [
                    'multiple' => Yup::boolean(),
                    'required' => $validators['required'],
                    'allowedTypes' => Yup::array()
                        ->of(Yup::string()->oneOf(['images', 'videos', 'files', 'audios']))
                        ->min(1),
                ];

            case 'uid':
                $uidTargets = [];
                foreach ($attributes as $key => $attr) {
                    if (in_array(is_array($attr) ? ($attr['type'] ?? null) : null, Constants::VALID_UID_TARGETS, true)) {
                        $uidTargets[] = (string) $key;
                    }
                }

                return [
                    'required' => $validators['required'],
                    'targetField' => Yup::string()->oneOf($uidTargets)->nullable(),
                    'default' => Yup::string()
                        ->test(
                            'isValidDefaultUID',
                            'cannot define a default UID if the targetField is set',
                            static function (mixed $value, TestContext $ctx): bool {
                                $targetField = is_array($ctx->parent) ? ($ctx->parent['targetField'] ?? null) : null;

                                return $targetField === null || self::isNil($value);
                            },
                        )
                        ->test(
                            'isValidDefaultRegexUID',
                            '${path} must match the custom regex or the default one "' . Common::UID_REGEX . '"',
                            static function (mixed $value, TestContext $ctx): bool {
                                $regex = is_array($ctx->parent) ? ($ctx->parent['regex'] ?? null) : null;

                                if (is_string($regex) && $regex !== '') {
                                    return !self::isNil($value)
                                        && ($value === '' || (Common::isValidRegExp($regex) && preg_match('/' . str_replace('/', '\/', $regex) . '/u', Common::jsString($value)) === 1));
                                }

                                return $value === '' || Common::regexTest(Common::UID_REGEX, $value);
                            },
                        ),
                    'minLength' => $validators['minLength'],
                    'maxLength' => $validators['maxLength']->max(256)->test(self::maxLengthIsGreaterThanOrEqualToMinLength()),
                    'options' => Yup::object()->shape([
                        'separator' => Yup::string(),
                        'lowercase' => Yup::boolean(),
                        'decamelize' => Yup::boolean(),
                        'customReplacements' => Yup::array()->of(Yup::array()->of(Yup::string())->min(2)->max(2)),
                        'preserveLeadingUnderscore' => Yup::boolean(),
                    ]),
                    'regex' => Yup::string()->test(Common::isValidRegExpPattern()),
                ];

            /*
             * scalar types
             */
            case 'string':
                return [
                    'default' => Yup::string(),
                    'required' => $validators['required'],
                    'unique' => $validators['unique'],
                    'minLength' => $validators['minLength'],
                    'maxLength' => $validators['maxLength']->max(255)->test(self::maxLengthIsGreaterThanOrEqualToMinLength()),
                    'regex' => Yup::string()->test(Common::isValidRegExpPattern()),
                ];
            case 'text':
                return [
                    'default' => Yup::string(),
                    'required' => $validators['required'],
                    'unique' => $validators['unique'],
                    'minLength' => $validators['minLength'],
                    'maxLength' => $validators['maxLength'],
                    'regex' => Yup::string()->test(Common::isValidRegExpPattern()),
                ];
            case 'richtext':
                return [
                    'default' => Yup::string(),
                    'required' => $validators['required'],
                    'minLength' => $validators['minLength'],
                    'maxLength' => $validators['maxLength'],
                ];
            case 'blocks':
                return [
                    'required' => $validators['required'],
                ];
            case 'json':
                return [
                    'default' => Yup::mixed()->test(Common::isValidDefaultJSON()),
                    'required' => $validators['required'],
                ];
            case 'enumeration':
                return [
                    'enum' => Yup::array()
                        ->of(Yup::string()->test(Common::isValidEnum())->required())
                        ->min(1)
                        ->test(Common::areEnumValuesUnique())
                        ->required(),
                    'default' => Yup::string()->when('enum', static fn (mixed $enumVal, YupString $schema): YupSchema => is_array($enumVal) ? Yup::string()->oneOf(array_values($enumVal)) : Yup::string()),
                    'enumName' => Yup::string()->test(Common::isValidName()),
                    'required' => $validators['required'],
                ];
            case 'password':
                return [
                    'required' => $validators['required'],
                    'minLength' => $validators['minLength'],
                    'maxLength' => $validators['maxLength'],
                ];
            case 'email':
                return [
                    'default' => Yup::string()->email(),
                    'required' => $validators['required'],
                    'unique' => $validators['unique'],
                    'minLength' => $validators['minLength'],
                    'maxLength' => $validators['maxLength'],
                ];
            case 'integer':
                return [
                    'default' => Yup::number()->integer(),
                    'required' => $validators['required'],
                    'unique' => $validators['unique'],
                    'min' => Yup::number()->integer(),
                    'max' => Yup::number()->integer(),
                ];
            case 'biginteger':
                return [
                    'default' => Yup::string()->nullable()->matches('/^\d*$/'),
                    'required' => $validators['required'],
                    'unique' => $validators['unique'],
                    'min' => Yup::string()->nullable()->matches('/^\d*$/'),
                    'max' => Yup::string()->nullable()->matches('/^\d*$/'),
                ];
            case 'float':
            case 'decimal':
                return [
                    'default' => Yup::number(),
                    'required' => $validators['required'],
                    'unique' => $validators['unique'],
                    'min' => Yup::number(),
                    'max' => Yup::number(),
                ];
            case 'time':
            case 'datetime':
            case 'date':
                return [
                    'default' => Yup::string(),
                    'required' => $validators['required'],
                    'unique' => $validators['unique'],
                ];
            case 'boolean':
                return [
                    'default' => Yup::boolean()->nullable(),
                    'required' => $validators['required'],
                ];

            case 'component':
                return [
                    'required' => $validators['required'],
                    'repeatable' => Yup::boolean(),
                    // TODO: Add correct server validation for nested components
                    'component' => Yup::string()->required(),
                    'min' => Yup::number(),
                    'max' => Yup::number(),
                ];

            case 'dynamiczone':
                return [
                    'required' => $validators['required'],
                    'components' => Yup::array()
                        ->of(Yup::string()->required())
                        ->test(
                            'isArray',
                            '${path} must be an array',
                            static fn (mixed $value): bool => is_array($value) && array_is_list($value),
                        )
                        ->min(1),
                    'min' => Yup::number(),
                    'max' => Yup::number(),
                ];

            default:
                return [];
        }
    }

    /** lodash `isNil`: null or undefined. */
    private static function isNil(mixed $value): bool
    {
        return $value === null || $value instanceof Undefined;
    }
}
