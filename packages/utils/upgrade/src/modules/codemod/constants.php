<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Codemod;

/**
 * Port of packages/utils/upgrade/src/modules/codemod/constants.ts. Codemods are PHP files
 * (`<name>.code.php`, `<name>.json.php`) where upstream has `.ts` ones.
 */
final class Constants
{
    public const CODEMOD_CODE_SUFFIX = 'code';

    public const CODEMOD_JSON_SUFFIX = 'json';

    public const CODEMOD_ALLOWED_SUFFIXES = [self::CODEMOD_CODE_SUFFIX, self::CODEMOD_JSON_SUFFIX];

    public const CODEMOD_EXTENSION = 'php';

    public const CODEMOD_FILE_REGEXP = '/^.+[.](' . self::CODEMOD_CODE_SUFFIX . '|' . self::CODEMOD_JSON_SUFFIX . ')[.]' . self::CODEMOD_EXTENSION . '$/';
}
