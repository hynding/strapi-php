<?php

declare(strict_types=1);

namespace Strapi\Upload;

use Strapi\Utils\Errors\PolicyError;

/**
 * Port of server/src/errors.ts. The file exports a single error class, kept under its upstream
 * name (Composer autoloads by classmap, so the file name need not match).
 */
final class FolderContainsUnauthorizedAssetsError extends PolicyError
{
    public function __construct()
    {
        parent::__construct('FolderContainsUnauthorizedAssetsError');
    }
}
