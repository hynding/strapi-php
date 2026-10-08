<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Engine\Validation;

use Strapi\DataTransfer\Engine\Errors\TransferEngineValidationError;

/**
 * Port of src/engine/validation/provider.ts.
 */
final class Provider
{
    private static function reject(string $reason): never
    {
        throw new TransferEngineValidationError("Invalid provider supplied. {$reason}");
    }

    public static function validateProvider(string $type, mixed $provider): void
    {
        if (!$provider) {
            self::reject('Expected an instance of "' . ucfirst($type) . 'Provider", but got "' . ($provider === null ? 'undefined' : gettype($provider)) . '" instead.');
        }

        $providerType = is_object($provider) && isset($provider->type) ? $provider->type : null;
        if ($providerType !== $type) {
            self::reject("Expected the provider to be of type \"{$type}\" but got \"" . (is_scalar($providerType) ? (string) $providerType : 'undefined') . '" instead.');
        }
    }
}
