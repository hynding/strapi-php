<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation;

use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodObject;

/** Port of server/src/validation/project-settings.ts. */
final class ProjectSettings
{
    private const MAX_IMAGE_WIDTH = 750;
    private const MAX_IMAGE_HEIGHT = self::MAX_IMAGE_WIDTH;
    private const MAX_IMAGE_FILE_SIZE = 1024 * 1024; // 1Mo

    public static function updateProjectSettings(): ZodObject
    {
        return z::object([
            'menuLogo' => z::string()->nullish(),
            'authLogo' => z::string()->nullish(),
        ])->strict();
    }

    public static function updateProjectSettingsLogo(): ZodObject
    {
        return z::object([
            'originalFilename' => z::string()->nullish(),
            'mimetype' => z::enum(['image/jpeg', 'image/png', 'image/svg+xml']),
            'size' => z::number()->max(self::MAX_IMAGE_FILE_SIZE)->nullish(),
        ]);
    }

    public static function updateProjectSettingsFiles(): ZodObject
    {
        return z::object([
            'menuLogo' => self::updateProjectSettingsLogo()->nullish(),
            'authLogo' => self::updateProjectSettingsLogo()->nullish(),
        ])->strict();
    }

    public static function logoDimensions(): ZodObject
    {
        return z::object([
            'width' => z::number()->max(self::MAX_IMAGE_WIDTH)->nullish(),
            'height' => z::number()->max(self::MAX_IMAGE_HEIGHT)->nullish(),
        ]);
    }

    public static function updateProjectSettingsImagesDimensions(): ZodObject
    {
        return z::object([
            'menuLogo' => self::logoDimensions()->nullish(),
            'authLogo' => self::logoDimensions()->nullish(),
        ])->strict();
    }

    /** @throws \Strapi\Utils\Errors\ValidationError */
    public static function validateUpdateProjectSettings(mixed $body, ?string $errorMessage = null): mixed
    {
        return z::validateZodSchema(self::updateProjectSettings())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\ValidationError */
    public static function validateUpdateProjectSettingsFiles(mixed $body, ?string $errorMessage = null): mixed
    {
        return z::validateZodSchema(self::updateProjectSettingsFiles())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\ValidationError */
    public static function validateUpdateProjectSettingsImagesDimensions(mixed $body, ?string $errorMessage = null): mixed
    {
        return z::validateZodSchema(self::updateProjectSettingsImagesDimensions())($body, $errorMessage);
    }
}
