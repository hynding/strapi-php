<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\I18n\Services\IsoLocales;

/**
 * Port of server/src/services/__tests__/iso-locales.test.ts. The upstream snapshot is the
 * iso-locales.json list itself: compared against upstream's file when a checkout is available.
 */
final class IsoLocalesTest extends TestCase
{
    public function testGetIsoLocales(): void
    {
        $locales = (new IsoLocales())->getIsoLocales();

        self::assertGreaterThan(500, count($locales));
        self::assertContains(['code' => 'en', 'name' => 'English (en)'], $locales);
        self::assertContains(['code' => 'fr', 'name' => 'French (fr)'], $locales);

        $upstream = getenv('STRAPI_UPSTREAM');
        $file = is_string($upstream) ? "{$upstream}/packages/plugins/i18n/server/src/constants/iso-locales.json" : null;
        if ($file !== null && is_file($file)) {
            self::assertSame(json_decode((string) file_get_contents($file), true), $locales);
        }
    }
}
