<?php

declare(strict_types=1);

namespace Strapi\Core\Configuration;

use Strapi\Core\Utils\LoadConfigFile;
use Strapi\Utils\EnvHelper;

/**
 * Port of packages/core/core/src/configuration/config-loader.ts: loads every `config/*.php|json`
 * of a directory into `[basename => value]`, with the same restricted / mistaken filename rules.
 */
final class ConfigLoader
{
    private const VALID_EXTENSIONS = ['.php', '.json'];

    // These filenames are restricted, but will also emit a warning that the filename is probably a mistake
    private const MISTAKEN_FILENAMES = [
        'middleware' => 'middlewares',
        'plugin' => 'plugins',
    ];

    // the following are restricted to prevent conflicts with existing STRAPI_* env vars or root level config options
    private const RESTRICTED_FILENAMES = [
        // existing env vars
        'uuid', 'hosting', 'license', 'enforce', 'disable', 'enable', 'telemetry',
        // reserved for future internal use
        'strapi', 'internal',
        // root level config options
        'launchedat', 'serveadminpanel', 'autoreload', 'environment', 'packagejsonstrapi', 'info', 'dirs',
        // probably mistaken/typo filenames
        'middleware', 'plugin',
    ];

    // Existing Strapi configuration files
    private const STRAPI_CONFIG_FILENAMES = ['admin', 'server', 'api', 'database', 'middlewares', 'plugins', 'features'];

    /** @var (callable(string): void)|null */
    private static $warn = null;

    /**
     * Override where warnings go (upstream uses console.warn: the logger does not exist yet).
     *
     * @param (callable(string): void)|null $handler
     */
    public static function setWarningHandler(?callable $handler): void
    {
        self::$warn = $handler;
    }

    private static function logWarning(string $message): void
    {
        if (self::$warn !== null) {
            (self::$warn)($message);

            return;
        }

        fwrite(STDERR, $message . PHP_EOL);
    }

    /** @return array<string, mixed> */
    public static function load(string $dir, ?EnvHelper $env = null): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $allFiles = scandir($dir) ?: [];
        sort($allFiles);
        $seenFilenames = [];
        $configFiles = [];

        foreach ($allFiles as $fileName) {
            if ($fileName === '.' || $fileName === '..') {
                continue;
            }
            $fullPath = $dir . DIRECTORY_SEPARATOR . $fileName;
            if (!is_file($fullPath)) {
                continue;
            }

            $extension = '.' . pathinfo($fileName, PATHINFO_EXTENSION);
            $baseName = pathinfo($fileName, PATHINFO_FILENAME);
            $baseNameLower = strtolower($baseName);
            $extensionLower = strtolower($extension);

            if (!in_array($extensionLower, self::VALID_EXTENSIONS, true)) {
                self::logWarning('Config file not loaded, extension must be one of ' . implode(',', self::VALID_EXTENSIONS) . "): {$fileName}");
                continue;
            }

            if (in_array($baseNameLower, self::RESTRICTED_FILENAMES, true)) {
                self::logWarning("Config file not loaded, restricted filename: {$fileName}");

                if (isset(self::MISTAKEN_FILENAMES[$baseNameLower])) {
                    self::logWarning('Did you mean ' . self::MISTAKEN_FILENAMES[$baseNameLower] . ' ?');
                }

                continue;
            }

            // restricted names and Strapi configs are also restricted from being prefixes
            foreach ([...self::RESTRICTED_FILENAMES, ...self::STRAPI_CONFIG_FILENAMES] as $restrictedName) {
                if (str_starts_with($restrictedName, $baseNameLower) && $restrictedName !== $baseNameLower) {
                    self::logWarning("Config file not loaded, filename cannot start with {$restrictedName}: {$fileName}");
                    break;
                }
            }

            // filter filenames without case-insensitive uniqueness
            if (in_array($baseNameLower, $seenFilenames, true)) {
                self::logWarning("Config file not loaded, case-insensitive name matches other config file: {$fileName}");
                continue;
            }
            $seenFilenames[] = $baseNameLower;

            $configFiles[$baseName] = $fullPath;
        }

        $out = [];
        foreach ($configFiles as $key => $path) {
            $out[$key] = LoadConfigFile::loadConfigFile($path, $env);
        }

        return $out;
    }
}
