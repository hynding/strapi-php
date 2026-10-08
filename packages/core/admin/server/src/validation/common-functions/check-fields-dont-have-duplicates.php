<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation\CommonFunctions;

/** Port of server/src/validation/common-functions/check-fields-dont-have-duplicates.ts. */
final class CheckFieldsDontHaveDuplicates
{
    public static function checkFieldsDontHaveDuplicates(mixed $fields): bool
    {
        if ($fields === null || $fields instanceof \Strapi\Utils\Yup\Undefined) {
            // Only check if the fields exist
            return true;
        }
        if (!is_array($fields) || !array_is_list($fields)) {
            return false;
        }

        return count(array_unique($fields, SORT_REGULAR)) === count($fields);
    }

    public function __invoke(mixed $fields): bool
    {
        return self::checkFieldsDontHaveDuplicates($fields);
    }
}
