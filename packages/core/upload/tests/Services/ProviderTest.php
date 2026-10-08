<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Services;

use Strapi\Tests\AppTestCase;
use Strapi\Upload\Provider as UploadProvider;
use Strapi\Upload\Utils\Utils;

/** Port of server/src/services/__tests__/provider.test.ts (`replace`), with a recording provider. */
final class ProviderTest extends AppTestCase
{
    private mixed $original = null;

    protected function setUp(): void
    {
        $this->original = self::strapi()->plugin('upload')->provider;
    }

    protected function tearDown(): void
    {
        self::strapi()->plugin('upload')->provider = $this->original;
    }

    /** @param array<string, \Closure> $methods */
    private static function useProvider(array $methods): \ArrayObject
    {
        $calls = new \ArrayObject();
        $instance = new \stdClass();
        foreach ($methods as $name => $fn) {
            $instance->{$name} = static function (mixed ...$args) use ($calls, $name, $fn): mixed {
                $calls[] = [$name, $args];

                return $fn(...$args);
            };
        }
        $instance->delete ??= static function (mixed ...$args) use ($calls): void {
            $calls[] = ['delete', $args];
        };
        self::strapi()->plugin('upload')->provider = new UploadProvider($instance);

        return $calls;
    }

    /** @return \ArrayObject<string, mixed> */
    private static function newFile(): \ArrayObject
    {
        $path = tempnam(sys_get_temp_dir(), 'strapi-provider-test-');
        file_put_contents((string) $path, 'new-content');

        return new \ArrayObject(['hash' => 'new', 'ext' => '.txt', 'filepath' => $path, 'getStream' => static fn () => fopen((string) $path, 'rb')]);
    }

    public function testUsesReplaceStreamWhenProviderImplementsIt(): void
    {
        $seen = null;
        $calls = self::useProvider([
            'replaceStream' => static function (\ArrayObject $file) use (&$seen): void {
                $seen = stream_get_contents($file['stream']);
            },
            'uploadStream' => static fn () => null,
        ]);

        Utils::getService('provider', self::strapi())->replace(self::newFile(), ['hash' => 'old', 'ext' => '.txt']);

        self::assertSame('replaceStream', $calls[0][0]);
        self::assertSame(['hash' => 'old', 'ext' => '.txt'], $calls[0][1][1]);
        self::assertSame('new-content', $seen);
    }

    public function testFallsBackToReplaceBufferWhenReplaceStreamIsNotImplemented(): void
    {
        $buffer = null;
        $calls = self::useProvider([
            'replace' => static function (\ArrayObject $file) use (&$buffer): void {
                $buffer = $file['buffer'];
            },
            'upload' => static fn () => null,
        ]);
        $file = self::newFile();

        Utils::getService('provider', self::strapi())->replace($file, ['hash' => 'old']);

        self::assertSame('replace', $calls[0][0]);
        self::assertSame('new-content', $buffer);
        self::assertArrayNotHasKey('buffer', $file);
    }

    public function testFallsBackToDeleteAndUploadWhenNeitherReplaceMethodIsImplemented(): void
    {
        $calls = self::useProvider(['uploadStream' => static fn () => null]);

        Utils::getService('provider', self::strapi())->replace(self::newFile(), ['hash' => 'old']);

        self::assertSame(['delete', 'uploadStream'], array_column($calls->getArrayCopy(), 0));
    }

    public function testFallbackUsesUploadBufferWhenUploadStreamIsNotImplemented(): void
    {
        $calls = self::useProvider(['upload' => static fn () => null]);

        Utils::getService('provider', self::strapi())->replace(self::newFile(), ['hash' => 'old']);

        self::assertSame(['delete', 'upload'], array_column($calls->getArrayCopy(), 0));
    }

    public function testCleansUpFilepathOnNewFileAfterReplaceStream(): void
    {
        self::useProvider(['replaceStream' => static fn () => null, 'uploadStream' => static fn () => null]);
        $file = self::newFile();

        Utils::getService('provider', self::strapi())->replace($file, ['hash' => 'old']);

        self::assertArrayNotHasKey('filepath', $file);
        self::assertArrayNotHasKey('stream', $file);
    }

    public function testCheckFileSizeUsesTheConfiguredSizeLimit(): void
    {
        self::useProvider(['uploadStream' => static fn () => null]);
        self::strapi()->config()->set('plugin::upload.sizeLimit', 1000);
        try {
            $this->expectException(\Strapi\Utils\Errors\PayloadTooLargeError::class);
            $this->expectExceptionMessage('big.txt exceeds size limit of 1 KB.');
            Utils::getService('provider', self::strapi())->checkFileSize(new \ArrayObject(['size' => 2, 'originalFilename' => 'big.txt']));
        } finally {
            self::strapi()->config()->set('plugin::upload.sizeLimit', 1000000000);
        }
    }
}
