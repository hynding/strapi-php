<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

/**
 * Port of packages/cli/create-strapi-app/src/utils/install-id.ts. `node-machine-id` reads the
 * OS machine id (Linux `/etc/machine-id`, macOS `IOPlatformUUID`, Windows `MachineGuid`) and
 * hashes it with sha256; this reads the same sources.
 */
final class InstallId
{
    public static function installID(?string $projectId = null, ?string $machineId = null): string
    {
        try {
            $machineId ??= self::machineIdSync();
            if ($machineId === null) {
                return self::randomUUID();
            }

            return $projectId !== null && $projectId !== ''
                ? hash('sha256', "{$machineId}-{$projectId}")
                : self::randomUUID();
        } catch (\Throwable) {
            return self::randomUUID();
        }
    }

    /** `crypto.randomUUID()`: an RFC 4122 version 4 UUID. */
    public static function randomUUID(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /** `machineIdSync()` (hashed, as node-machine-id returns it by default). */
    public static function machineIdSync(): ?string
    {
        $raw = null;

        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $file) {
            if (is_readable($file)) {
                $raw = trim((string) file_get_contents($file));
                break;
            }
        }

        if (($raw === null || $raw === '') && PHP_OS_FAMILY === 'Darwin') {
            $out = (string) shell_exec('ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null');
            if (preg_match('/"IOPlatformUUID"\s*=\s*"([^"]+)"/', $out, $m) === 1) {
                $raw = $m[1];
            }
        }

        if (($raw === null || $raw === '') && PHP_OS_FAMILY === 'Windows') {
            $out = (string) shell_exec('REG QUERY HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Cryptography /v MachineGuid 2>NUL');
            if (preg_match('/MachineGuid\s+REG_SZ\s+(\S+)/', $out, $m) === 1) {
                $raw = $m[1];
            }
        }

        return $raw === null || $raw === '' ? null : hash('sha256', strtolower($raw));
    }
}
