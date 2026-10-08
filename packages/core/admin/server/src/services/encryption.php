<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Core\Strapi;

/**
 * Port of server/src/services/encryption.ts (`admin::encryption`): AES-256-GCM with a SHA-256 of
 * `admin.secrets.encryptionKey`, serialized as `v1:<iv hex>:<ciphertext hex>:<tag hex>` (the same
 * format as upstream, so values encrypted by either backend decrypt on the other).
 */
final class Encryption
{
    private const IV_LENGTH = 16; // 16 bytes for AES-GCM IV
    private const ENCRYPTION_VERSION = 'v1';

    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function getHashedKey(): ?string
    {
        $secrets = $this->strapi->config()->get('admin.secrets');
        $rawKey = is_array($secrets) ? ($secrets['encryptionKey'] ?? null) : null;
        if (!is_string($rawKey) || $rawKey === '') {
            $this->strapi->log()->warning('Encryption key is missing from admin.secrets.encryptionKey configuration');

            return null;
        }

        return hash('sha256', $rawKey, true); // Always 32 bytes
    }

    /**
     * Encrypts a value string using AES-256-GCM.
     * Returns a string prefixed with the encryption version and includes IV, encrypted content, and auth tag (all hex-encoded).
     */
    public function encrypt(string $value): ?string
    {
        $key = $this->getHashedKey();
        if ($key === null) {
            return null;
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($encrypted === false) {
            throw new \RuntimeException('Unable to encrypt value');
        }

        return self::ENCRYPTION_VERSION . ':' . bin2hex($iv) . ':' . bin2hex($encrypted) . ':' . bin2hex($tag);
    }

    /**
     * Decrypts a value encrypted by encrypt().
     * Supports versioned formats like v1:iv:encrypted:authTag
     */
    public function decrypt(string $encryptedValue): ?string
    {
        $parts = explode(':', $encryptedValue);
        $version = array_shift($parts);

        if ($version !== self::ENCRYPTION_VERSION) {
            throw new \RuntimeException("Unsupported encryption version: {$version}");
        }

        [$ivHex, $encryptedHex, $tagHex] = [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''];
        if ($ivHex === '' || $encryptedHex === '' || $tagHex === '') {
            throw new \RuntimeException('Invalid encrypted value format');
        }

        $key = $this->getHashedKey();
        if ($key === null) {
            return null;
        }

        $iv = @hex2bin($ivHex);
        $encryptedText = @hex2bin($encryptedHex);
        $authTag = @hex2bin($tagHex);

        $decrypted = $iv === false || $encryptedText === false || $authTag === false || $iv === ''
            ? false
            : openssl_decrypt($encryptedText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $authTag);

        if ($decrypted === false) {
            $this->strapi->log()->warning('[decrypt] Unable to decrypt value — encryption key may have changed or data is corrupted.');

            return null;
        }

        return $decrypted;
    }
}
