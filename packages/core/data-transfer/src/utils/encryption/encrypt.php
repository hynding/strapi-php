<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Encryption;

/**
 * Port of src/utils/encryption/encrypt.ts: the key is derived with scrypt (empty salt) and the
 * algorithm defaults to `aes-128-ecb`, as upstream does, so archives are interchangeable with
 * Node Strapi's.
 */
final class Encrypt
{
    public const array ALGORITHMS = ['aes-128-ecb', 'aes128', 'aes192', 'aes256'];

    /**
     * It creates a cipher instance used for encryption
     *
     * @param string $key       The encryption key
     * @param string $algorithm The algorithm to use to create the Cipher
     */
    public static function createEncryptionCipher(string $key, string $algorithm = 'aes-128-ecb'): Cipher
    {
        return self::strategy($algorithm, $key, true);
    }

    /** different key values depending on algorithm chosen (shared with {@see Decrypt}) */
    public static function strategy(string $algorithm, string $key, bool $encrypt): Cipher
    {
        switch ($algorithm) {
            case 'aes-128-ecb':
                return new Cipher('aes-128-ecb', Scrypt::scryptSync($key, '', 16), null, $encrypt);
            case 'aes128':
                $hashedKey = Scrypt::scryptSync($key, '', 32);

                return new Cipher('aes-128-cbc', substr($hashedKey, 0, 16), substr($hashedKey, 16), $encrypt);
            case 'aes192':
                $hashedKey = Scrypt::scryptSync($key, '', 40);

                return new Cipher('aes-192-cbc', substr($hashedKey, 0, 24), substr($hashedKey, 24), $encrypt);
            case 'aes256':
                $hashedKey = Scrypt::scryptSync($key, '', 48);

                return new Cipher('aes-256-cbc', substr($hashedKey, 0, 32), substr($hashedKey, 32), $encrypt);
        }

        throw new \InvalidArgumentException("Invalid encryption algorithm \"{$algorithm}\"");
    }
}
