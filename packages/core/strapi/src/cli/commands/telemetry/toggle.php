<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Telemetry;

use Symfony\Component\Console\Output\OutputInterface;

/** Not an upstream file: writes `extra.strapi.telemetryDisabled` (and a `uuid`) into the project's composer.json. */
final class Toggle
{
    public static function set(string $cwd, bool $disabled, OutputInterface $output): void
    {
        $path = $cwd . '/composer.json';
        if (!is_file($path)) {
            $output->writeln('<comment>Warning</comment>: could not find composer.json');

            return;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json)) {
            $output->writeln('<comment>Warning</comment>: composer.json is not valid JSON');

            return;
        }

        $strapi = is_array($json['extra']['strapi'] ?? null) ? $json['extra']['strapi'] : [];
        if (($strapi['telemetryDisabled'] ?? null) === $disabled && isset($strapi['uuid'])) {
            $output->writeln('<comment>Warning:</comment> telemetry is already ' . ($disabled ? 'disabled' : 'enabled'));

            return;
        }

        $strapi['uuid'] = is_string($strapi['uuid'] ?? null) ? $strapi['uuid'] : self::uuid();
        $strapi['telemetryDisabled'] = $disabled;
        $json['extra']['strapi'] = $strapi;

        file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
