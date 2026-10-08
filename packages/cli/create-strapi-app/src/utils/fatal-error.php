<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

/**
 * Thrown by {@see Logger::fatal()} where upstream calls `process.exit(1)`; the command catches it
 * and exits with 1. No upstream file. It extends \RuntimeException rather than strapi/utils'
 * ApplicationError: like upstream's create-strapi-app, this package depends on no Strapi runtime
 * package (it runs before any is installed).
 */
final class FatalError extends \RuntimeException
{
}
