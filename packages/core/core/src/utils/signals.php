<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/utils/signals.ts: destroy Strapi on SIGTERM/SIGINT. Only effective
 * under the CLI SAPI with ext-pcntl (FPM/FrankenPHP manage the process lifecycle themselves).
 */
final class Signals
{
    public static function destroyOnSignal(Strapi $strapi): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('pcntl_signal')) {
            return;
        }

        $signalReceived = false;
        $terminateStrapi = static function () use ($strapi, &$signalReceived): void {
            if (!$signalReceived) {
                $signalReceived = true;
                $strapi->destroy();
                exit(0);
            }
        };

        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, $terminateStrapi);
        }
    }
}
