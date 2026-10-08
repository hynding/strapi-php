<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests;

use Strapi\Provider\UploadLocal\UploadLocal;
use Strapi\Tests\AppTestCase;
use Strapi\Upload\Provider;
use Strapi\Upload\Register;

/** Port of server/src/__tests__/register.test.ts (provider loading; sharp options have no GD counterpart). */
final class RegisterTest extends AppTestCase
{
    public function testTheUploadPluginRegistersTheUploadsRoute(): void
    {
        $paths = array_map(static fn (array $route): string => (string) ($route['path'] ?? ''), self::strapi()->server()->listRoutes());

        self::assertContains('/uploads/(.*)', $paths);
    }

    public function testTheLocalProviderIsResolvedFromItsComposerPackage(): void
    {
        $provider = self::strapi()->plugin('upload')->provider;

        self::assertInstanceOf(Provider::class, $provider);
        self::assertInstanceOf(UploadLocal::class, $provider->instance());
        self::assertTrue($provider->has('uploadStream'));
        self::assertFalse($provider->isPrivate());
    }

    public function testStrapiConfigCanProgramaticallyBeExtendedByProviders(): void
    {
        $provider = Register::createProvider(self::strapi(), ['provider' => 'local']);
        $provider->extend(['getSignedUrl' => static fn (array $file): array => ['url' => 'signed']]);

        self::assertSame(['url' => 'signed'], $provider->getSignedUrl(['url' => 'x']));
    }

    public function testAnUnknownProviderCannotBeLoaded(): void
    {
        $this->expectExceptionMessage('Could not load upload provider "nope-not-installed".');
        Register::createProvider(self::strapi(), ['provider' => 'nope-not-installed']);
    }

    public function testActionOptionsAreForwarded(): void
    {
        $seen = null;
        $instance = new class ($seen) {
            public function __construct(public mixed &$seen)
            {
            }

            public function upload(mixed $file, mixed $options): void
            {
                $this->seen = $options;
            }

            public function delete(): void
            {
            }
        };

        $provider = new Provider($instance, ['upload' => ['ACL' => 'private']]);
        $provider->upload(['hash' => 'x']);

        self::assertSame(['ACL' => 'private'], $seen);
    }
}
