<?php

declare(strict_types=1);

namespace Strapi\Upload;

use Strapi\Upload\Shared\Constants as SharedConstants;

/** Port of server/src/constants.ts. */
final class Constants
{
    public const array ACTIONS = [
        'read' => 'plugin::upload.read',
        'readSettings' => 'plugin::upload.settings.read',
        'create' => 'plugin::upload.assets.create',
        'update' => 'plugin::upload.assets.update',
        'download' => 'plugin::upload.assets.download',
        'copyLink' => 'plugin::upload.assets.copy-link',
        'configureView' => 'plugin::upload.configure-view',
    ];

    public const array ALLOWED_SORT_STRINGS = [
        'createdAt:DESC',
        'createdAt:ASC',
        'name:ASC',
        'name:DESC',
        'updatedAt:DESC',
        'updatedAt:ASC',
    ];

    public const array ALLOWED_WEBHOOK_EVENTS = [
        'MEDIA_CREATE' => 'media.create',
        'MEDIA_UPDATE' => 'media.update',
        'MEDIA_DELETE' => 'media.delete',
    ];

    public const string FOLDER_MODEL_UID = 'plugin::upload.folder';

    public const string FILE_MODEL_UID = 'plugin::upload.file';

    public const string API_UPLOAD_FOLDER_BASE_NAME = 'API Uploads';

    // Re-exported from `shared/` so the admin panel can gate on the same values.
    public const int AI_METADATA_CHUNK_SIZE = SharedConstants::AI_METADATA_CHUNK_SIZE;

    public const int AI_METADATA_MAX_FILES = SharedConstants::AI_METADATA_MAX_FILES;

    public const array AI_METADATA_SUPPORTED_IMAGE_TYPES = SharedConstants::AI_METADATA_SUPPORTED_IMAGE_TYPES;
}
