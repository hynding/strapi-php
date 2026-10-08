<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation\CommonFunctions;

/** Port of server/src/validation/common-functions/check-fields-are-correctly-nested.ts. */
final class CheckFieldsAreCorrectlyNested
{
    public static function checkFieldsAreCorrectlyNested(mixed $fields): bool
    {
        if ($fields === null || $fields instanceof \Strapi\Utils\Yup\Undefined) {
            // Only check if the fields exist
            return true;
        }
        if (!is_array($fields) || !array_is_list($fields)) {
            return false;
        }

        $count = count($fields);
        for ($indexA = 0; $indexA < $count; $indexA += 1) {
            $fieldA = (string) $fields[$indexA];
            for ($indexB = $indexA + 1; $indexB < $count; $indexB += 1) {
                $fieldB = (string) $fields[$indexB];
                if (str_starts_with($fieldB, "{$fieldA}.") || str_starts_with($fieldA, "{$fieldB}.")) {
                    return false;
                }
            }
        }

        return true;
    }

    public function __invoke(mixed $fields): bool
    {
        return self::checkFieldsAreCorrectlyNested($fields);
    }
}
