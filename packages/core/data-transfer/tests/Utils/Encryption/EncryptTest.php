<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Utils\Encryption;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\Utils\Encryption\Decrypt;
use Strapi\DataTransfer\Utils\Encryption\Encrypt;
use Strapi\DataTransfer\Utils\Encryption\Scrypt;

/** Port of src/utils/encryption/__tests__/encrypt.test.ts, plus byte compatibility with Node's crypto. */
final class EncryptTest extends TestCase
{
    private const string TEXT = 'something ate an apple';

    public function testEncryptingDataWithDefaultAlgorithm(): void
    {
        $cipher = Encrypt::createEncryptionCipher('password');
        $encrypted = $cipher->update(self::TEXT);

        self::assertNotSame(self::TEXT, $encrypted);
    }

    /** @return iterable<string, array{string}> */
    public static function algorithms(): iterable
    {
        yield 'aes128' => ['aes128'];
        yield 'aes192' => ['aes192'];
        yield 'aes256' => ['aes256'];
    }

    #[DataProvider('algorithms')]
    public function testEncryptingDataWithAlgorithm(string $algorithm): void
    {
        $cipher = Encrypt::createEncryptionCipher('password', $algorithm);
        $encrypted = $cipher->update(self::TEXT);

        self::assertNotSame(self::TEXT, $encrypted);
    }

    public function testDataEncryptedWithDifferentAlgorithmsShouldHaveDifferentResults(): void
    {
        $aes256 = Encrypt::createEncryptionCipher('password', 'aes256')->update(self::TEXT);
        $aes192 = Encrypt::createEncryptionCipher('password', 'aes192')->update(self::TEXT);
        $default = Encrypt::createEncryptionCipher('password')->update(self::TEXT);

        self::assertNotSame($aes256, $default);
        self::assertNotSame($aes256, $aes192);
        self::assertNotSame($default, $aes192);
    }

    public function testDataEncryptedWithDifferentKeyShouldBeDifferent(): void
    {
        self::assertNotSame(
            Encrypt::createEncryptionCipher('password')->update(self::TEXT),
            Encrypt::createEncryptionCipher('differentpassword')->update(self::TEXT),
        );
    }

    /**
     * Ciphertexts produced by upstream's createEncryptionCipher('password', algorithm) (Node crypto,
     * `update(text) + final()`): an archive encrypted by either implementation opens in the other.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function nodeVectors(): iterable
    {
        yield 'aes-128-ecb' => ['aes-128-ecb', 'ff12fadf7afea75e69d4f57766bdaa77c0f127431f8be51fff41b0df7670c3fd'];
        yield 'aes128' => ['aes128', 'db257e017226ed1b11eac225eb61dc91b544e614e95d91f84748f4f7a4a2d8ac'];
        yield 'aes192' => ['aes192', '0b9c0a3ac246dccf134ad34ed3ba19ea66a93d47b698e9e6dd8b54066203b41b'];
        yield 'aes256' => ['aes256', '35b00db6d5fed99cfefccbcd0c3268c934339a5350620aa33fb26831cd68a4f1'];
    }

    #[DataProvider('nodeVectors')]
    public function testCiphertextIsByteIdenticalToNode(string $algorithm, string $expectedHex): void
    {
        $cipher = Encrypt::createEncryptionCipher('password', $algorithm);
        // fed in uneven pieces: update() buffers partial blocks as Node's Cipher does
        $out = $cipher->update(substr(self::TEXT, 0, 5)) . $cipher->update(substr(self::TEXT, 5)) . $cipher->final();
        self::assertSame($expectedHex, bin2hex($out));

        $decipher = Decrypt::createDecryptionCipher('password', $algorithm);
        self::assertSame(self::TEXT, $decipher->update(substr($out, 0, 7)) . $decipher->update(substr($out, 7)) . $decipher->final());
    }

    public function testScryptMatchesRfc7914(): void
    {
        // RFC 7914 §12, second vector (N=1024, r=8, p=16)
        self::assertSame(
            'fdbabe1c9d3472007856e7190d01e9fe7c6ad7cbc8237830e77376634b3731622eaf30d92e22a3886ff109279d9830dac727afb94a83ee6d8360cbdfa2cc0640',
            bin2hex(Scrypt::scryptSync('password', 'NaCl', 64, 1024, 8, 16)),
        );
    }
}
