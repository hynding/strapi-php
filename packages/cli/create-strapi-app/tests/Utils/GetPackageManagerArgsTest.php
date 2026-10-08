<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\CreateStrapiApp\Utils\GetPackageManagerArgs;

/**
 * Port of packages/cli/create-strapi-app/src/utils/__tests__/get-package-manager-args.test.ts
 * (execa is mocked through the injectable runner).
 */
final class GetPackageManagerArgsTest extends TestCase
{
    /** @return callable(string, list<string>, array<string, mixed>): string */
    private static function mockedExeca(string $stdout): callable
    {
        return static fn (): string => $stdout;
    }

    public function testDoesNotPassLegacyPeerDepsForNpm(): void
    {
        ['cmdArgs' => $cmdArgs] = GetPackageManagerArgs::getInstallArgs('npm', [], self::mockedExeca('10.9.2'));

        self::assertSame(['install'], $cmdArgs);
        self::assertNotContains('--legacy-peer-deps', $cmdArgs);
    }

    public function testKeepsYarnClassicNetworkTimeout(): void
    {
        ['cmdArgs' => $cmdArgs] = GetPackageManagerArgs::getInstallArgs('yarn', [], self::mockedExeca('1.22.22'));

        self::assertSame(['install', '--network-timeout', '1000000'], $cmdArgs);
    }

    public function testDoesNotAddExtraArgsForYarn4(): void
    {
        ['cmdArgs' => $cmdArgs, 'envArgs' => $envArgs] = GetPackageManagerArgs::getInstallArgs('yarn', [], self::mockedExeca('4.12.0'));

        self::assertSame(['install'], $cmdArgs);
        self::assertSame(['YARN_HTTP_TIMEOUT' => '1000000'], $envArgs);
    }

    public function testDoesNotAddExtraArgsForPnpm(): void
    {
        ['cmdArgs' => $cmdArgs] = GetPackageManagerArgs::getInstallArgs('pnpm', [], self::mockedExeca('10.25.0'));

        self::assertSame(['install'], $cmdArgs);
    }

    public function testReportsAVersionDetectionFailure(): void
    {
        $this->expectExceptionMessage('Error detecting npm version');

        GetPackageManagerArgs::getInstallArgs('npm', [], static function (): string {
            throw new \RuntimeException('not found');
        });
    }
}
