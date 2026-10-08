<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Upload\Constants;
use Strapi\Upload\Controllers\Validation\Admin\Settings;
use Strapi\Upload\Utils\Utils;

/** Port of server/src/controllers/admin-settings.ts. */
final class AdminSettings
{
    // The stored settings' known keys. `GET /upload/settings` echoes read-only
    // config (e.g. `concurrentUploadRequests`) alongside them, and the legacy
    // Settings page PUTs the whole payload back — narrowing to these keys keeps
    // those echoes out of the store. Derived from the schema so it can't drift.
    private const array SETTINGS_KEYS = Settings::SETTINGS_KEYS;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function updateSettings(Context $ctx): void
    {
        $body = $ctx->requestBody();
        $userAbility = $ctx->state()->get('userAbility');

        if ($userAbility->cannot(Constants::ACTIONS['readSettings'], Constants::FILE_MODEL_UID)) {
            $ctx->forbidden();

            return;
        }

        $validated = Settings::validateSettings($body);
        $data = [];
        foreach (self::SETTINGS_KEYS as $key) {
            if (array_key_exists($key, $validated)) {
                $data[$key] = $validated[$key];
            }
        }

        Utils::getService('upload', $this->strapi)->setSettings($data);

        $ctx->setBody(['data' => $data]);
    }

    public function getSettings(Context $ctx): void
    {
        // Gated on `plugin::upload.read` by the route policy.
        $data = Utils::getService('upload', $this->strapi)->getSettings() ?? [];

        // Read-only echo of the app config so the admin knows how many upload
        // requests it may fire in parallel. Distinct from `concurrentUploadSize`,
        // which is the server-side per-request processing ceiling. Deployments that
        // need to bound this override the config.
        $concurrentUploadRequests = $this->strapi->config()->get('plugin::upload.concurrentUploadRequests') ?? 1;

        $ctx->setBody([
            'data' => [
                ...$data,
                'concurrentUploadRequests' => $concurrentUploadRequests,
                'aiMetadataAvailable' => Utils::getService('aiMetadataProvider', $this->strapi)->hasProvider(),
            ],
        ]);
    }
}
