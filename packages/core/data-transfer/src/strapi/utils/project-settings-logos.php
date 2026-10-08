<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Utils;

use Strapi\Core\Strapi;
use Strapi\Upload\Utils\MimeTypes;

/**
 * Port of src/strapi/utils/project-settings-logos.ts: the admin logos (`menuLogo`, `authLogo`)
 * of the `core_admin_project-settings` core-store row travel with the configuration stage as a
 * base64 `__transferBuffer` and are re-uploaded through the destination's upload provider.
 */
final class ProjectSettingsLogos
{
    public const string PROJECT_SETTINGS_CORE_STORE_KEY = 'core_admin_project-settings';

    public const array PROJECT_SETTINGS_LOGO_FIELDS = ['menuLogo', 'authLogo'];

    /**
     * The shape persisted back to core-store, matching what the admin project-settings
     * service stores (so the admin UI keeps behaving identically after a transfer).
     */
    private const array LOGO_PERSISTED_FIELDS = ['name', 'hash', 'url', 'width', 'height', 'ext', 'size', 'provider'];

    /** @param array<string, mixed> $row */
    private static function isProjectSettingsRow(array $row): bool
    {
        return ($row['key'] ?? null) === self::PROJECT_SETTINGS_CORE_STORE_KEY;
    }

    private static function getConfiguredUploadProvider(Strapi $strapi): ?string
    {
        $config = $strapi->config()->get('plugin::upload');
        $provider = is_array($config) ? ($config['provider'] ?? null) : null;

        return is_string($provider) ? $provider : null;
    }

    /**
     * Admin logos with no provider were uploaded with the local provider (the default).
     *
     * @param array<string, mixed> $logo
     */
    private static function isLocalProviderLogo(array $logo): bool
    {
        return empty($logo['provider']) || $logo['provider'] === 'local';
    }

    /**
     * Best-effort mime type for a logo so that remote providers (S3, Cloudinary, …)
     * store the correct Content-Type and the image renders instead of downloading.
     * The admin does not persist the mime type, so it is reconstructed from the
     * file extension (falling back to the file name).
     *
     * @param array<string, mixed> $logo
     */
    private static function getLogoMimeType(array $logo): ?string
    {
        $fromExt = is_string($logo['ext'] ?? null) && $logo['ext'] !== '' ? MimeTypes::lookup($logo['ext']) : false;
        if ($fromExt) {
            return $fromExt;
        }

        $fromName = is_string($logo['name'] ?? null) && $logo['name'] !== '' ? MimeTypes::lookup($logo['name']) : false;
        if ($fromName) {
            return $fromName;
        }

        return null;
    }

    /**
     * Resolve the URL to read a non-local logo from during export.
     *
     * @param array<string, mixed> $logo
     */
    private static function getRemoteLogoUrl(Strapi $strapi, array $logo): string
    {
        $providerName = self::getConfiguredUploadProvider($strapi);

        if (($logo['provider'] ?? null) === $providerName && UploadProvider::isPrivate($strapi)) {
            return (string) UploadProvider::signedUrl($strapi, $logo);
        }

        return (string) ($logo['url'] ?? '');
    }

    /** @param array<string, mixed> $logo */
    private static function logoLabel(array $logo): string
    {
        return (string) ($logo['name'] ?? $logo['hash'] ?? 'unknown');
    }

    /** @param array<string, mixed> $logo */
    private static function readLocalLogoBuffer(Strapi $strapi, array $logo): ?string
    {
        $filepath = rtrim($strapi->dirs()->public, '/') . '/' . ltrim((string) $logo['url'], '/');

        if (!is_file($filepath)) {
            $strapi->log()->warning("[Data transfer] Admin logo \"" . self::logoLabel($logo) . "\" exists in project settings but no corresponding file was found to transfer. Path: {$filepath}");

            return null;
        }

        $buffer = file_get_contents($filepath);
        if ($buffer === false) {
            throw new \RuntimeException("Could not read {$filepath}");
        }

        return base64_encode($buffer);
    }

    /** @param array<string, mixed> $logo */
    private static function readRemoteLogoBuffer(Strapi $strapi, array $logo): ?string
    {
        $url = self::getRemoteLogoUrl($strapi, $logo);
        $response = ($strapi->fetch())($url);

        if ($response['status'] !== 200 || $response['body'] === '') {
            $strapi->log()->warning("[Data transfer] Admin logo \"" . self::logoLabel($logo) . "\" exists in project settings but could not be fetched for transfer. URL: {$url} (status: {$response['status']})");

            return null;
        }

        return base64_encode($response['body']);
    }

    /** @param array<string, mixed> $logo */
    private static function readLogoTransferBuffer(Strapi $strapi, array $logo): ?string
    {
        if (empty($logo['url'])) {
            return null;
        }

        if (self::isLocalProviderLogo($logo)) {
            return self::readLocalLogoBuffer($strapi, $logo);
        }

        return self::readRemoteLogoBuffer($strapi, $logo);
    }

    private static function enrichLogoForExport(Strapi $strapi, mixed $logo): mixed
    {
        if (!is_array($logo) || empty($logo['url'])) {
            return $logo;
        }

        $transferBuffer = self::readLogoTransferBuffer($strapi, $logo);

        if ($transferBuffer === null) {
            return $logo;
        }

        return [...$logo, '__transferBuffer' => $transferBuffer];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function enrichProjectSettingsForExport(Strapi $strapi, array $row): array
    {
        if (!self::isProjectSettingsRow($row)) {
            return $row;
        }

        $settings = is_array($row['value'] ?? null) ? $row['value'] : [];

        // `{ ...settings, menuLogo, authLogo }`: an undefined logo stays absent once serialized
        foreach (self::PROJECT_SETTINGS_LOGO_FIELDS as $field) {
            if (array_key_exists($field, $settings)) {
                $settings[$field] = self::enrichLogoForExport($strapi, $settings[$field]);
            }
        }

        return [...$row, 'value' => $settings];
    }

    /**
     * Re-upload a transferred logo to the destination's configured upload provider.
     *
     * The file is always re-uploaded through the destination provider, so the
     * persisted `url` and `provider` reflect the destination (matching how the
     * media-library asset restore behaves). A best-effort mime type is supplied so
     * remote providers serve the logo with the correct Content-Type.
     *
     * @param array<string, mixed> $logo
     *
     * @return array<string, mixed>
     */
    private static function uploadLogoFromTransferBuffer(Strapi $strapi, array $logo): array
    {
        if (empty($logo['__transferBuffer'])) {
            return $logo;
        }

        $transferBuffer = (string) $logo['__transferBuffer'];
        unset($logo['__transferBuffer']);
        $provider = self::getConfiguredUploadProvider($strapi);
        $mime = self::getLogoMimeType($logo);

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Could not create a temporary stream');
        }
        fwrite($stream, (string) base64_decode($transferBuffer, false));
        rewind($stream);

        $file = new \ArrayObject([
            ...$logo,
            ...($mime !== null ? ['mime' => $mime] : []),
            'stream' => $stream,
            'provider' => $provider,
        ]);

        try {
            UploadProvider::call($strapi, 'uploadStream', $file);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $persisted = [];
        foreach (self::LOGO_PERSISTED_FIELDS as $field) {
            if ($file->offsetExists($field)) {
                $persisted[$field] = $file[$field];
            }
        }

        return $persisted;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public static function restoreProjectSettingsLogos(Strapi $strapi, array $settings): array
    {
        foreach (self::PROJECT_SETTINGS_LOGO_FIELDS as $field) {
            $logo = $settings[$field] ?? null;
            if (is_array($logo) && $logo !== []) {
                $settings[$field] = self::uploadLogoFromTransferBuffer($strapi, $logo);
            }
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function restoreProjectSettingsRow(Strapi $strapi, array $row): array
    {
        if (!self::isProjectSettingsRow($row)) {
            return $row;
        }

        return [
            ...$row,
            'value' => self::restoreProjectSettingsLogos($strapi, is_array($row['value'] ?? null) ? $row['value'] : []),
        ];
    }
}
