<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\I18n\AuditLogs;

/** Port of server/src/__tests__/audit-logs.test.ts. */
final class AuditLogsTest extends TestCase
{
    /** @return array<string, callable> */
    private static function getTransformers(): array
    {
        $lifecycle = new class () {
            /** @var array<string, callable> */
            public array $transformers = [];

            public function registerEvent(string $name, callable $transform): void
            {
                $this->transformers[$name] = $transform;
            }
        };
        AuditLogs::registerAuditEvents($lifecycle);

        return $lifecycle->transformers;
    }

    public function testRegistersTheFourLocaleEvents(): void
    {
        $names = array_keys(self::getTransformers());
        sort($names);
        self::assertSame(['locale.create', 'locale.default.update', 'locale.delete', 'locale.update'], $names);
    }

    public function testLocaleCreate(): void
    {
        self::assertSame([
            'resource' => ['type' => 'locale', 'id' => 2, 'name' => 'French (France)', 'code' => 'fr'],
            'details' => ['isDefault' => false],
        ], self::getTransformers()['locale.create'](['localeId' => 2, 'name' => 'French (France)', 'code' => 'fr', 'isDefault' => false]));
    }

    public function testLocaleUpdate(): void
    {
        $changes = ['name' => ['before' => 'French (old)', 'after' => 'French (France)']];
        self::assertSame([
            'resource' => ['type' => 'locale', 'id' => 2, 'name' => 'French (France)', 'code' => 'fr'],
            'details' => ['changes' => $changes],
        ], self::getTransformers()['locale.update'](['localeId' => 2, 'name' => 'French (France)', 'code' => 'fr', 'changes' => $changes]));
    }

    public function testLocaleDeleteCarriesNoDetails(): void
    {
        self::assertSame([
            'resource' => ['type' => 'locale', 'id' => 2, 'name' => 'French (France)', 'code' => 'fr'],
        ], self::getTransformers()['locale.delete'](['localeId' => 2, 'name' => 'French (France)', 'code' => 'fr']));
    }

    public function testLocaleDefaultUpdatePointsAtTheNewDefaultLocale(): void
    {
        $changes = ['defaultLocale' => ['before' => ['id' => 2, 'code' => 'en'], 'after' => ['id' => 1, 'code' => 'fr']]];
        self::assertSame([
            'resource' => ['type' => 'locale', 'id' => 1, 'name' => 'French (France)', 'code' => 'fr'],
            'details' => ['changes' => $changes],
        ], self::getTransformers()['locale.default.update'](['localeId' => 1, 'name' => 'French (France)', 'code' => 'fr', 'changes' => $changes]));
    }

    public function testLocaleDefaultUpdateRecordsNoPreviousDefaultOnTheFirstOne(): void
    {
        $changes = ['defaultLocale' => ['before' => null, 'after' => ['id' => 1, 'code' => 'en']]];
        self::assertSame([
            'resource' => ['type' => 'locale', 'id' => 1, 'name' => 'English (en)', 'code' => 'en'],
            'details' => ['changes' => $changes],
        ], self::getTransformers()['locale.default.update'](['localeId' => 1, 'name' => 'English (en)', 'code' => 'en', 'changes' => $changes]));
    }
}
