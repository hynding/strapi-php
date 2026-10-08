<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers\Validation;

use Strapi\Utils\Primitives\Strings;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\YupBoolean;
use Strapi\Utils\Yup\YupNumber;

/**
 * Port of server/src/controllers/validation/common.ts.
 *
 * The regexes are PCRE patterns; `*_REGEX_SOURCE` is the JavaScript source upstream interpolates
 * in its messages (`${NAME_REGEX}` prints `/^[A-Za-z][_0-9A-Za-z]*$/`).
 *
 * Like `RegExp.prototype.test`, the tests convert their value to a string first, so `undefined`
 * and `null` are tested as "undefined" / "null".
 *
 * @phpstan-type CommonTestConfig array{name: string, message: string, test: \Closure(mixed, mixed=): bool}
 */
final class Common
{
    public const string NAME_REGEX = '/^[A-Za-z][_0-9A-Za-z]*$/';
    public const string COLLECTION_NAME_REGEX = '/^[A-Za-z][-_0-9A-Za-z]*$/';
    public const string CATEGORY_NAME_REGEX = '/^[A-Za-z][-_0-9A-Za-z]*$/';
    public const string ICON_REGEX = '/^[A-Za-z0-9][-A-Za-z0-9]*$/';
    public const string UID_REGEX = '/^[A-Za-z0-9-_.~]*$/';
    public const string KEBAB_BASE_REGEX = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    // Fully-qualified content-type uid: `api::a.a` / `plugin::p.p` / `strapi::x` / `admin::x`.
    public const string CONTENT_TYPE_UID_REGEX = '/^((strapi|admin)::[\w-]+|(api|plugin)::[\w-]+\.[\w-]+)$/';

    /** @return array{required: YupBoolean, unique: YupBoolean, minLength: YupNumber, maxLength: YupNumber} */
    public static function validators(): array
    {
        return [
            'required' => Yup::boolean(),
            'unique' => Yup::boolean(),
            'minLength' => Yup::number()->integer()->positive(),
            'maxLength' => Yup::number()->integer()->positive(),
        ];
    }

    /** `String(value)` as `RegExp.prototype.test` sees it. */
    public static function jsString(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value instanceof Undefined, $value instanceof \Strapi\Utils\Zod\Undefined => 'undefined',
            $value === true => 'true',
            $value === false => 'false',
            is_array($value) => array_is_list($value) ? implode(',', array_map(self::jsString(...), $value)) : '[object Object]',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    public static function regexTest(string $regex, mixed $value): bool
    {
        return preg_match($regex, self::jsString($value)) === 1;
    }

    /** `new RegExp(source)` does not throw. */
    public static function isValidRegExp(mixed $source): bool
    {
        $pattern = '/' . str_replace('/', '\/', self::jsString($source)) . '/u';

        return @preg_match($pattern, '') !== false;
    }

    /** @return CommonTestConfig */
    public static function isValidName(): array
    {
        return [
            'name' => 'isValidName',
            'message' => '${path} must match the following regex: ' . self::NAME_REGEX,
            'test' => static fn (mixed $val): bool => $val === '' || self::regexTest(self::NAME_REGEX, $val),
        ];
    }

    /** @return CommonTestConfig */
    public static function isValidIcon(): array
    {
        return [
            'name' => 'isValidIcon',
            'message' => '${path} is not a valid icon name. Make sure your icon name starts with an alphanumeric character and only includes alphanumeric characters or dashes.',
            'test' => static fn (mixed $val): bool => $val === '' || self::regexTest(self::ICON_REGEX, $val),
        ];
    }

    /** @return CommonTestConfig */
    public static function isValidUID(): array
    {
        return [
            'name' => 'isValidUID',
            'message' => '${path} must match the following regex: ' . self::UID_REGEX,
            'test' => static fn (mixed $val): bool => $val === '' || self::regexTest(self::UID_REGEX, $val),
        ];
    }

    /** @return CommonTestConfig */
    public static function isValidCategoryName(): array
    {
        return [
            'name' => 'isValidCategoryName',
            'message' => '${path} must start with a letter and only contain letters, numbers, dashes and underscores',
            'test' => static fn (mixed $val): bool => $val === '' || self::regexTest(self::CATEGORY_NAME_REGEX, $val),
        ];
    }

    /** @return CommonTestConfig */
    public static function isValidCollectionName(): array
    {
        return [
            'name' => 'isValidCollectionName',
            'message' => '${path} must match the following regex: ' . self::COLLECTION_NAME_REGEX,
            'test' => static fn (mixed $val): bool => $val === '' || self::regexTest(self::COLLECTION_NAME_REGEX, $val),
        ];
    }

    /** @return CommonTestConfig */
    public static function isValidKey(string $key): array
    {
        return [
            'name' => 'isValidKey',
            'message' => "Attribute name '{$key}' must match the following regex: " . self::NAME_REGEX,
            'test' => static fn (): bool => preg_match(self::NAME_REGEX, $key) === 1,
        ];
    }

    /** @return CommonTestConfig */
    public static function isValidEnum(): array
    {
        return [
            'name' => 'isValidEnum',
            'message' => '${path} should not start with number',
            'test' => static fn (mixed $val): bool => $val === '' || !Strings::startsWithANumber(self::jsString($val)),
        ];
    }

    /** @return CommonTestConfig */
    public static function areEnumValuesUnique(): array
    {
        return [
            'name' => 'areEnumValuesUnique',
            'message' => '${path} cannot contain duplicate values',
            'test' => static function (mixed $values): bool {
                if (!is_array($values)) {
                    return true;
                }
                $filtered = [];
                foreach ($values as $value) {
                    if (!in_array($value, $filtered, true)) {
                        $filtered[] = $value;
                    }
                }

                return count($filtered) === count($values);
            },
        ];
    }

    /** @return CommonTestConfig */
    public static function isValidRegExpPattern(): array
    {
        return [
            'name' => 'isValidRegExpPattern',
            'message' => '${path} must be a valid RexExp pattern string',
            'test' => static fn (mixed $val): bool => $val === '' || self::isValidRegExp($val),
        ];
    }

    /** @return CommonTestConfig */
    public static function isValidDefaultJSON(): array
    {
        return [
            'name' => 'isValidDefaultJSON',
            'message' => '${path} is not a valid JSON',
            'test' => static function (mixed $val): bool {
                if ($val instanceof Undefined) {
                    return true;
                }

                if (is_int($val) || is_float($val) || $val === null || is_array($val)) {
                    return true;
                }

                return json_validate(self::jsString($val));
            },
        ];
    }
}
