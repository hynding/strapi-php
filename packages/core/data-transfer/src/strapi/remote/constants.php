<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Remote;

/**
 * Port of src/strapi/remote/constants.ts.
 */
final class Constants
{
    public const string TRANSFER_PATH = '/transfer/runner';

    public const array TRANSFER_METHODS = ['push', 'pull'];
}
