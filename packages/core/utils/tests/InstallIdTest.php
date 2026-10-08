<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\InstallId;

/** packages/core/utils/src/install-id.ts (no upstream test). */
final class InstallIdTest extends TestCase
{
    public function testKeepsAnExistingInstallId(): void
    {
        self::assertSame('abc', InstallId::generateInstallId('project', 'abc'));
    }

    public function testHashesTheMachineIdWithTheProjectId(): void
    {
        $expected = hash('sha256', InstallId::machineIdSync() . '-project');

        self::assertSame($expected, InstallId::generateInstallId('project', null));
        self::assertSame($expected, InstallId::generateInstallId('project', ''));
    }

    public function testFallsBackToARandomUuidWithoutProjectId(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', InstallId::generateInstallId(null, null));
    }
}
