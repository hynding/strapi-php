<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Controllers;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\I18n\Constants\Constants;
use Strapi\Plugin\I18n\Controllers\IsoLocales;
use Strapi\Plugin\I18n\Tests\I18nTestApp;

/** Port of server/src/controllers/__tests__/iso-locales.test.ts. */
final class IsoLocalesTest extends TestCase
{
    public function testListIsoLocales(): void
    {
        $ctx = I18nTestApp::ctx();
        (new IsoLocales(I18nTestApp::create()))->listIsoLocales($ctx);

        self::assertSame(Constants::isoLocales(), $ctx->body());
    }
}
