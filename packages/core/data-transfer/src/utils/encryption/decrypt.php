<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Encryption;

/**
 * Port of src/utils/encryption/decrypt.ts.
 */
final class Decrypt
{
    /**
     * It creates a cipher instance used for decryption
     *
     * @param string $key       The decryption key
     * @param string $algorithm The algorithm to use to create the Cipher
     */
    public static function createDecryptionCipher(string $key, string $algorithm = 'aes-128-ecb'): Cipher
    {
        return Encrypt::strategy($algorithm, $key, false);
    }
}
