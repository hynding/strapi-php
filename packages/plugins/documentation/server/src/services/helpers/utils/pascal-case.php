<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services\Helpers\Utils;

use Strapi\Utils\Primitives\Strings;

/** Port of server/src/services/helpers/utils/pascal-case.ts (`_.upperFirst(_.camelCase(string))`). */
final class PascalCase
{
    public static function pascalCase(string $string): string
    {
        return Strings::upperFirst(Strings::camelCase($string));
    }
}
