<?php

declare(strict_types=1);

namespace Strapi\Upload;

use Strapi\Core\Strapi;

/** Port of server/src/media-library-default-notice.ts. */
final class MediaLibraryDefaultNotice
{
    public const string NOTICE_STORE_KEY = 'media_library_default_notice';

    public const string NOTICE = 'The Media Library has been redesigned and is now the default. To keep the previous one, set `useLegacyMediaLibrary: true` in config/features.';

    /**
     * Tells an upgrading app, once, that its Media Library changed.
     *
     * At GA every existing app changes behaviour with nothing in its config to hint at it,
     * because nobody has a flag set. Three conditions keep this from becoming noise:
     *
     * - only when the flag is absent — setting it either way is a deliberate choice, and
     *   `false` is how an app says "I know, I want the new one"
     * - only for an app that has booted this plugin before, so a fresh install (which has
     *   no previous Media Library to lose) stays quiet
     * - only once, tracked in the plugin store rather than in memory, so restarts and
     *   multi-instance deployments do not repeat it
     */
    public static function notifyMediaLibraryDefault(Strapi $strapi, bool $isExistingApp): void
    {
        $optOut = $strapi->config()->get('features.useLegacyMediaLibrary');

        if ($optOut !== null || !$isExistingApp) {
            return;
        }

        $store = ['type' => 'plugin', 'name' => 'upload', 'key' => self::NOTICE_STORE_KEY];

        if ($strapi->store()->get($store) !== null) {
            return;
        }

        $strapi->log()->info(self::NOTICE);
        $strapi->store()->set([...$store, 'value' => ['shown' => true]]);
    }
}
