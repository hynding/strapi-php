<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

/**
 * Port of server/src/services/constants.ts. The constants are class constants; the registry
 * entry (`admin::constants`) is an instance whose public properties mirror them, as upstream's
 * service is the plain constants object.
 */
final class Constants
{
    private const DAY_IN_MS = 24 * 60 * 60 * 1000;

    public const CONTENT_TYPE_SECTION = 'contentTypes';
    public const SUPER_ADMIN_CODE = 'strapi-super-admin';
    public const EDITOR_CODE = 'strapi-editor';
    public const AUTHOR_CODE = 'strapi-author';
    public const READ_ACTION = 'plugin::content-manager.explorer.read';
    public const CREATE_ACTION = 'plugin::content-manager.explorer.create';
    public const UPDATE_ACTION = 'plugin::content-manager.explorer.update';
    public const DELETE_ACTION = 'plugin::content-manager.explorer.delete';
    public const PUBLISH_ACTION = 'plugin::content-manager.explorer.publish';
    public const API_TOKEN_TYPE = [
        'READ_ONLY' => 'read-only',
        'FULL_ACCESS' => 'full-access',
        'CUSTOM' => 'custom',
    ];
    // The front-end only displays these values
    public const API_TOKEN_LIFESPANS = [
        'UNLIMITED' => null,
        'DAYS_7' => 7 * self::DAY_IN_MS,
        'DAYS_30' => 30 * self::DAY_IN_MS,
        'DAYS_90' => 90 * self::DAY_IN_MS,
    ];
    public const DEFAULT_API_TOKENS = [
        [
            'name' => 'Read Only',
            'description' => 'A default API token with read-only permissions, only used for accessing resources',
            'kind' => 'content-api',
            'type' => 'read-only',
            'lifespan' => null,
        ],
        [
            'name' => 'Full Access',
            'description' => 'A default API token with full access permissions, used for accessing or modifying resources',
            'kind' => 'content-api',
            'type' => 'full-access',
            'lifespan' => null,
        ],
    ];
    public const TRANSFER_TOKEN_TYPE = [
        'PUSH' => 'push',
        'PULL' => 'pull',
    ];
    public const TRANSFER_TOKEN_LIFESPANS = [
        'UNLIMITED' => null,
        'DAYS_7' => 7 * self::DAY_IN_MS,
        'DAYS_30' => 30 * self::DAY_IN_MS,
        'DAYS_90' => 90 * self::DAY_IN_MS,
    ];

    /** Registry entry: the constants as properties (`strapi.service('admin::constants').SUPER_ADMIN_CODE`). */
    public function __get(string $name): mixed
    {
        $constant = self::class . '::' . $name;
        if ($name !== 'DAY_IN_MS' && defined($constant)) {
            return constant($constant);
        }

        return null;
    }

    public function __isset(string $name): bool
    {
        return $name !== 'DAY_IN_MS' && defined(self::class . '::' . $name);
    }
}
