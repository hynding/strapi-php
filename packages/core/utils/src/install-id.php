<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/install-id.ts.
 *
 * `node-machine-id`'s `machineIdSync()` reads the OS machine id (Linux `/var/lib/dbus/machine-id`,
 * `/etc/machine-id`, else the hostname; macOS `IOPlatformUUID`; Windows `MachineGuid`; FreeBSD
 * `smbios.system.uuid`) and returns its sha256; this reads the same sources.
 */
final class InstallId
{
    public static function generateInstallId(?string $projectId, ?string $installId): string
    {
        if ($installId !== null && $installId !== '') {
            return $installId;
        }

        try {
            $machineId = self::machineIdSync();

            return $projectId !== null && $projectId !== ''
                ? hash('sha256', "{$machineId}-{$projectId}")
                : self::randomUUID();
        } catch (\Throwable) {
            return self::randomUUID();
        }
    }

    /** `machineIdSync()` of node-machine-id (hashed, its default). */
    public static function machineIdSync(): string
    {
        $raw = match (PHP_OS_FAMILY) {
            'Darwin' => preg_match('/"IOPlatformUUID"\s*=\s*"([^"]+)"/', (string) shell_exec('ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null'), $m) === 1 ? $m[1] : '',
            'Windows' => preg_match('/REG_SZ\s+(\S+)/', (string) shell_exec('REG QUERY HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Cryptography /v MachineGuid 2>NUL'), $m) === 1 ? $m[1] : '',
            'BSD' => (string) shell_exec('kenv -q smbios.system.uuid 2>/dev/null'),
            default => self::linuxMachineId(),
        };
        $raw = strtolower((string) preg_replace('/\s+/', '', $raw));
        if ($raw === '') {
            throw new \RuntimeException('Error while obtaining machine id');
        }

        return hash('sha256', $raw);
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

    /** `( cat /var/lib/dbus/machine-id /etc/machine-id 2> /dev/null || hostname ) | head -n 1` */
    private static function linuxMachineId(): string
    {
        foreach (['/var/lib/dbus/machine-id', '/etc/machine-id'] as $file) {
            if (is_readable($file)) {
                $line = strtok((string) file_get_contents($file), "\n");
                if ($line !== false && trim($line) !== '') {
                    return $line;
                }
            }
        }

        return (string) gethostname();
    }
}
