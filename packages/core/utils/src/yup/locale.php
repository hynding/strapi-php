<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/**
 * yup 0.32.9 `locale.js` default messages, with the `mixed.notType` message @strapi/utils
 * yup.ts installs through `setLocale` (no "If null is intended..." hint).
 */
final class Locale
{
    public const string MIXED_DEFAULT = '${path} is invalid';
    public const string MIXED_REQUIRED = '${path} is a required field';
    public const string MIXED_ONE_OF = '${path} must be one of the following values: ${values}';
    public const string MIXED_NOT_ONE_OF = '${path} must not be one of the following values: ${values}';
    public const string MIXED_DEFINED = '${path} must be defined';

    public const string STRING_LENGTH = '${path} must be exactly ${length} characters';
    public const string STRING_MIN = '${path} must be at least ${min} characters';
    public const string STRING_MAX = '${path} must be at most ${max} characters';
    public const string STRING_MATCHES = '${path} must match the following: "${regex}"';
    public const string STRING_EMAIL = '${path} must be a valid email';
    public const string STRING_URL = '${path} must be a valid URL';
    public const string STRING_UUID = '${path} must be a valid UUID';
    public const string STRING_TRIM = '${path} must be a trimmed string';
    public const string STRING_LOWERCASE = '${path} must be a lowercase string';
    public const string STRING_UPPERCASE = '${path} must be a upper case string';

    public const string NUMBER_MIN = '${path} must be greater than or equal to ${min}';
    public const string NUMBER_MAX = '${path} must be less than or equal to ${max}';
    public const string NUMBER_LESS_THAN = '${path} must be less than ${less}';
    public const string NUMBER_MORE_THAN = '${path} must be greater than ${more}';
    public const string NUMBER_POSITIVE = '${path} must be a positive number';
    public const string NUMBER_NEGATIVE = '${path} must be a negative number';
    public const string NUMBER_INTEGER = '${path} must be an integer';

    public const string BOOLEAN_IS_VALUE = '${path} field must be ${value}';

    public const string OBJECT_NO_UNKNOWN = '${path} field has unspecified keys: ${unknown}';

    public const string ARRAY_MIN = '${path} field must have at least ${min} items';
    public const string ARRAY_MAX = '${path} field must have less than or equal to ${max} items';
    public const string ARRAY_LENGTH = '${path} must be have ${length} items';

    /**
     * yup.ts `setLocale({ mixed: { notType } })`.
     *
     * @param array<string, mixed> $params `path`, `type`, `value`, `originalValue`
     */
    public static function notType(array $params): string
    {
        $path = Yup::jsString($params['path'] ?? Undefined::value());
        $type = Yup::jsString($params['type'] ?? Undefined::value());
        $value = array_key_exists('value', $params) ? $params['value'] : Undefined::value();
        $originalValue = array_key_exists('originalValue', $params) ? $params['originalValue'] : Undefined::value();
        $isCast = !Yup::isAbsent($originalValue) && !Yup::sameValue($originalValue, $value);

        return "{$path} must be a `{$type}` type, but the final value was: `" . Yup::print($value, true) . '`'
            . ($isCast ? ' (cast from the value `' . Yup::print($originalValue, true) . '`).' : '.');
    }
}
