<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests;

use Strapi\Tests\AppTestCase;
use Strapi\Upload\MediaLibraryDefaultNotice;

/** Port of server/src/__tests__/media-library-default-notice.test.ts (on a booted app). */
final class MediaLibraryDefaultNoticeTest extends AppTestCase
{
    private const array STORE = ['type' => 'plugin', 'name' => 'upload', 'key' => MediaLibraryDefaultNotice::NOTICE_STORE_KEY];

    protected function setUp(): void
    {
        self::strapi()->store()->delete(self::STORE);
        self::strapi()->config()->set('features.useLegacyMediaLibrary', null);
    }

    public function testTellsAnUpgradingAppThatTookTheNewDefault(): void
    {
        MediaLibraryDefaultNotice::notifyMediaLibraryDefault(self::strapi(), true);

        self::assertStringContainsString('useLegacyMediaLibrary', MediaLibraryDefaultNotice::NOTICE);
        // Persisted rather than remembered, so a restart does not repeat it.
        self::assertSame(['shown' => true], self::strapi()->store()->get(self::STORE));
    }

    public function testStaysQuietOnAFreshInstall(): void
    {
        MediaLibraryDefaultNotice::notifyMediaLibraryDefault(self::strapi(), false);

        self::assertNull(self::strapi()->store()->get(self::STORE));
    }

    public function testStaysQuietWhenTheFlagIsSet(): void
    {
        foreach ([true, false] as $optOut) {
            self::strapi()->config()->set('features.useLegacyMediaLibrary', $optOut);

            MediaLibraryDefaultNotice::notifyMediaLibraryDefault(self::strapi(), true);

            self::assertNull(self::strapi()->store()->get(self::STORE));
        }
    }

    public function testDoesNotRepeatItselfOnceShown(): void
    {
        self::strapi()->store()->set([...self::STORE, 'value' => ['shown' => 'earlier']]);

        MediaLibraryDefaultNotice::notifyMediaLibraryDefault(self::strapi(), true);

        self::assertSame(['shown' => 'earlier'], self::strapi()->store()->get(self::STORE));
    }
}
