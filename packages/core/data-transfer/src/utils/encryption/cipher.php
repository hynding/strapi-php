<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Encryption;

/**
 * Not an upstream file: a streaming `Cipheriv` / `Decipheriv` (Node's `crypto` API: `update()`
 * then `final()`) over OpenSSL. Blocks are processed as they complete; PKCS#7 padding is added by
 * `final()` when encrypting and checked and removed when decrypting, as Node does by default.
 * CBC chaining carries the last ciphertext block over as the next IV.
 */
final class Cipher
{
    private const int BLOCK = 16;

    private string $buffer = '';

    private bool $finalized = false;

    /**
     * @param string      $algorithm an OpenSSL cipher name (`aes-128-ecb`, `aes-128-cbc`, …)
     * @param string|null $iv        null for ECB
     */
    public function __construct(
        private readonly string $algorithm,
        private readonly string $key,
        private ?string $iv,
        private readonly bool $encrypt,
    ) {
    }

    public function update(string $data): string
    {
        if ($this->finalized) {
            throw new \LogicException('Cipher already finalized');
        }

        $this->buffer .= $data;
        $length = strlen($this->buffer);
        // when decrypting, keep the last full block back: it holds the padding
        $usable = $length - ($length % self::BLOCK);
        if (!$this->encrypt && $usable === $length) {
            $usable -= self::BLOCK;
        }
        if ($usable <= 0) {
            return '';
        }

        $blocks = substr($this->buffer, 0, $usable);
        $this->buffer = (string) substr($this->buffer, $usable);

        return $this->run($blocks);
    }

    public function final(): string
    {
        if ($this->finalized) {
            throw new \LogicException('Cipher already finalized');
        }
        $this->finalized = true;

        if ($this->encrypt) {
            $pad = self::BLOCK - (strlen($this->buffer) % self::BLOCK);

            return $this->run($this->buffer . str_repeat(chr($pad), $pad));
        }

        if (strlen($this->buffer) !== self::BLOCK) {
            throw new \RuntimeException('error:1C80006B:Provider routines::wrong final block length');
        }

        $plain = $this->run($this->buffer);
        $pad = ord($plain[self::BLOCK - 1]);
        if ($pad < 1 || $pad > self::BLOCK || substr($plain, -$pad) !== str_repeat(chr($pad), $pad)) {
            throw new \RuntimeException('error:1C800064:Provider routines::bad decrypt');
        }

        return substr($plain, 0, self::BLOCK - $pad);
    }

    private function run(string $blocks): string
    {
        if ($blocks === '') {
            return '';
        }

        $flags = OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING;
        $out = $this->encrypt
            ? openssl_encrypt($blocks, $this->algorithm, $this->key, $flags, $this->iv ?? '')
            : openssl_decrypt($blocks, $this->algorithm, $this->key, $flags, $this->iv ?? '');

        if ($out === false) {
            throw new \RuntimeException('Cipher error: ' . (openssl_error_string() ?: 'unknown'));
        }

        if ($this->iv !== null) {
            // CBC: the next IV is the last ciphertext block
            $cipherText = $this->encrypt ? $out : $blocks;
            $this->iv = substr($cipherText, -self::BLOCK);
        }

        return $out;
    }
}
