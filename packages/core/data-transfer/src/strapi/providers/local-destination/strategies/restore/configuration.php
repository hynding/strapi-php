<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Strapi\Utils\ProjectSettingsLogos;
use Strapi\DataTransfer\Utils\Json;
use Strapi\DataTransfer\Utils\Stream\Writable;
use Strapi\DataTransfer\Utils\Transaction;

/**
 * Port of src/strapi/providers/local-destination/strategies/restore/configuration.ts.
 */
final class Configuration
{
    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function restoreCoreStore(Strapi $strapi, array $values): array
    {
        unset($values['id']);
        $row = ProjectSettingsLogos::restoreProjectSettingsRow($strapi, $values);

        return $strapi->db()->query('strapi::core-store')->create([
            'data' => [
                ...$row,
                'value' => Json::stringify($row['value'] ?? null),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function restoreWebhooks(Strapi $strapi, array $values): array
    {
        unset($values['id']);

        return $strapi->db()->query('strapi::webhook')->create(['data' => $values]);
    }

    /**
     * @param array<string, mixed> $config `{ type: 'core-store'|'webhook', value }`
     *
     * @return array<string, mixed>|null
     */
    public static function restoreConfigs(Strapi $strapi, array $config): ?array
    {
        $value = is_array($config['value'] ?? null) ? $config['value'] : [];

        if (($config['type'] ?? null) === 'core-store') {
            return self::restoreCoreStore($strapi, $value);
        }

        if (($config['type'] ?? null) === 'webhook') {
            return self::restoreWebhooks($strapi, $value);
        }

        return null;
    }

    public static function createConfigurationWriteStream(Strapi $strapi, ?Transaction $transaction = null): Writable
    {
        return new Writable(write: static function (mixed $config) use ($strapi, $transaction): void {
            $config = is_array($config) ? $config : [];
            $run = static function () use ($strapi, $config): void {
                try {
                    self::restoreConfigs($strapi, $config);
                } catch (\Throwable $error) {
                    $type = (string) ($config['type'] ?? 'undefined');
                    $id = $config['value']['id'] ?? null;
                    throw new ProviderTransferError("Failed to import \e[93m{$type}\e[39m (\e[92m" . (is_scalar($id) ? (string) $id : 'undefined') . "\e[39m): {$error->getMessage()}");
                }
            };

            if ($transaction !== null) {
                $transaction->attach($run);
            }
        });
    }
}
