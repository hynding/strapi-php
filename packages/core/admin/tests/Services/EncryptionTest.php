<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';
require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Encryption;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Admin\Tests\RecordingLogger;
use Strapi\Admin\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of server/src/services/__tests__/encryption.test.ts. */
final class EncryptionTest extends TestCase
{
    private Strapi $strapi;

    private RecordingLogger $logs;

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
        $this->strapi->config()->set('admin.secrets', ['encryptionKey' => bin2hex(random_bytes(32))]);
        $this->logs = BootedAdminApp::recordLogs($this->strapi);
    }

    public function testEncryptsAndReturnsAFourPartColonSeparatedString(): void
    {
        $encrypted = (new Encryption($this->strapi))->encrypt('super secret');

        self::assertMatchesRegularExpression('/^v1:[a-f0-9]+:[a-f0-9]+:[a-f0-9]+$/', (string) $encrypted);
        self::assertCount(4, explode(':', (string) $encrypted));
    }

    public function testEncryptReturnsNullAndLogsWarningWhenKeyIsMissing(): void
    {
        $this->strapi->config()->set('admin.secrets', null);

        self::assertNull((new Encryption($this->strapi))->encrypt('test'));
        self::assertNotEmpty($this->logs->messages('warning'));
    }

    public function testRoundTrips(): void
    {
        $service = new Encryption($this->strapi);

        self::assertSame('round trip', $service->decrypt((string) $service->encrypt('round trip')));
    }

    public function testDecryptThrowsForMalformedInput(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Encryption($this->strapi))->decrypt('invalid');
    }

    public function testDecryptReturnsNullAndLogsWarningWhenKeyIsMissing(): void
    {
        $encrypted = (string) (new Encryption($this->strapi))->encrypt('test');
        $this->strapi->config()->set('admin.secrets', null);

        self::assertNull((new Encryption($this->strapi))->decrypt($encrypted));
        self::assertNotEmpty($this->logs->messages('warning'));
    }

    public function testDecryptReturnsNullAndLogsWarningWithAWrongKey(): void
    {
        $encrypted = (string) (new Encryption($this->strapi))->encrypt('test');
        $this->strapi->config()->set('admin.secrets', ['encryptionKey' => 'another key']);

        self::assertNull((new Encryption($this->strapi))->decrypt($encrypted));
        self::assertStringContainsString('Unable to decrypt value', $this->logs->messages('warning')[0] ?? '');
    }

    public function testMatchesNodeCryptoFormat(): void
    {
        // produced by upstream's encrypt() with encryptionKey 'tobemodified'
        $this->strapi->config()->set('admin.secrets', ['encryptionKey' => 'tobemodified']);
        $key = hash('sha256', 'tobemodified', true);
        $iv = str_repeat("\1", 16);
        $tag = '';
        $cipher = (string) openssl_encrypt('hello', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        self::assertSame('hello', (new Encryption($this->strapi))->decrypt('v1:' . bin2hex($iv) . ':' . bin2hex($cipher) . ':' . bin2hex($tag)));
    }
}
