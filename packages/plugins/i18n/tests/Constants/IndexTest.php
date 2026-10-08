<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Constants;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\I18n\Constants\Constants;

/** Port of server/src/constants/__tests__/index.test.ts. */
final class IndexTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('STRAPI_PLUGIN_I18N_INIT_LOCALE_CODE');
        unset($_ENV['STRAPI_PLUGIN_I18N_INIT_LOCALE_CODE']);
    }

    public function testTheInitLocaleIsEnglishByDefault(): void
    {
        self::assertSame(['code' => 'en', 'name' => 'English (en)'], Constants::getInitLocale());
    }

    public function testTheInitLocaleCanBeConfiguredByAnEnvVar(): void
    {
        putenv('STRAPI_PLUGIN_I18N_INIT_LOCALE_CODE=fr');
        self::assertSame(['code' => 'fr', 'name' => 'French (fr)'], Constants::getInitLocale());
    }

    public function testThrowsIfEnvVarCodeIsUnknownInIsoList(): void
    {
        putenv('STRAPI_PLUGIN_I18N_INIT_LOCALE_CODE=zzzzz');
        $this->expectException(\RuntimeException::class);
        Constants::getInitLocale();
    }

    public function testNoDuplicateLocalesInIsoLocalesJson(): void
    {
        $codes = array_map(static fn (array $l): string => $l['code'], Constants::isoLocales());
        self::assertSame(count($codes), count(array_unique($codes)));
    }
}
