<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Remote\Handlers;

/**
 * Port of src/strapi/remote/handlers/constants.ts.
 */
final class Constants
{
    public const array VALID_TRANSFER_COMMANDS = ['init', 'end', 'status'];
}
