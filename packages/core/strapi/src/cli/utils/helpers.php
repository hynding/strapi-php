<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Utils;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/utils/helpers.ts: the helpers the ported commands use
 * (`readableBytes`, `readableTime`, `exitWith`, `assertCwdContainsStrapiProject`, `runAction`).
 * Transfer-only helpers (`ifOptions`, `notifyExperimentalCommand`...) are not ported.
 */
final class Helpers
{
    private const BYTES_PER_KB = 1024;

    private const SIZES = ['B ', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /** Convert bytes to a human readable formatted string, for example "1024" becomes "1KB" */
    public static function readableBytes(int|float $bytes, int $decimals = 1, int $padStart = 0): string
    {
        if (!$bytes) {
            return '0';
        }
        $i = (int) floor(log($bytes) / log(self::BYTES_PER_KB));
        $result = number_format($bytes / (self::BYTES_PER_KB ** $i), $decimals, '.', '') . ' ' . str_pad(self::SIZES[$i], 2, ' ', STR_PAD_LEFT);

        return str_pad($result, $padStart, ' ', STR_PAD_LEFT);
    }

    /** Convert elapsed time (ms) to a human readable formatted string, for example "1024" becomes "1s" */
    public static function readableTime(int|float $elapsedTime, int $decimals = 1, int $padStart = 0): string
    {
        if ($elapsedTime >= 60000) {
            $value = $elapsedTime / 60000;
            $unit = 'm';
        } elseif ($elapsedTime >= 1000) {
            $value = $elapsedTime / 1000;
            $unit = 's';
        } else {
            $value = $elapsedTime;
            $unit = 'ms';
        }

        $factor = 10 ** $decimals;
        $floored = floor($value * $factor) / $factor;
        $result = number_format($floored, $decimals, '.', '') . $unit;

        return str_pad($result, $padStart, ' ', STR_PAD_LEFT);
    }

    /**
     * Display message(s) and return the exit code (upstream calls `process.exit(code)`; symfony
     * commands return the code instead). Green for 0, red otherwise.
     *
     * @param string|list<string>|null $message
     */
    public static function exitWith(int $code, string|array|null $message = null, ?OutputInterface $output = null): int
    {
        $messages = is_string($message) ? [$message] : ($message ?? []);
        foreach ($messages as $msg) {
            if ($output !== null) {
                $output->writeln($code === 0 ? "<info>{$msg}</info>" : "<error>{$msg}</error>");
            } else {
                fwrite($code === 0 ? STDOUT : STDERR, Logger::colorize($msg, $code === 0 ? 'green' : 'red') . "\n");
            }
        }

        return $code;
    }

    /**
     * Upstream checks `package.json` depends on `@strapi/strapi`; here a Strapi project is one whose
     * `composer.json` requires `hynding/strapi-php` (the published package), or `strapi/strapi` /
     * `strapi/core` (the package names it replaces).
     */
    public static function isStrapiProject(string $cwd): bool
    {
        $composer = $cwd . '/composer.json';
        if (!is_file($composer)) {
            return false;
        }
        $json = json_decode((string) file_get_contents($composer), true);
        if (!is_array($json)) {
            return false;
        }
        $deps = [...($json['require'] ?? []), ...($json['require-dev'] ?? [])];

        return isset($deps['hynding/strapi-php']) || isset($deps['strapi/strapi']) || isset($deps['strapi/core']);
    }

    public static function assertCwdContainsStrapiProject(string $name, string $cwd, OutputInterface $output): bool
    {
        if (self::isStrapiProject($cwd)) {
            return true;
        }

        $output->writeln("You need to run <comment>strapi {$name}</comment> in a Strapi project. Make sure you are in the right directory.");

        return false;
    }

    /**
     * `runAction(name, action)`: asserts the cwd is a Strapi project and turns exceptions into
     * exit code 1 with the error printed.
     *
     * @param callable(InputInterface, OutputInterface): int $action
     */
    public static function runAction(string $name, string $cwd, callable $action): \Closure
    {
        return static function (InputInterface $input, OutputInterface $output) use ($name, $cwd, $action): int {
            if (!self::assertCwdContainsStrapiProject($name, $cwd, $output)) {
                return Command::FAILURE;
            }

            try {
                return $action($input, $output);
            } catch (\Throwable $error) {
                $output->writeln('<error>' . get_class($error) . ': ' . $error->getMessage() . '</error>');
                if ($output->isVerbose()) {
                    $output->writeln($error->getTraceAsString());
                }

                return Command::FAILURE;
            }
        };
    }

    public const string TRANSFER_PROGRESS_FIELD_SEP = ' · ';

    /**
     * Stage / prep timing: plain `readableTime` (e.g. `1.2s`) when no ETA; with ETA, append
     * `, ~4.0s remaining` (`~` = approximate). Same base format for every stage; remaining is additive.
     */
    public static function formatElapsedAndMaybeRemainingLabel(int|float $elapsedMs, int|float|null $remainingMs): string
    {
        return $remainingMs !== null
            ? self::readableTime($elapsedMs) . ', ~' . self::readableTime($remainingMs) . ' remaining'
            : self::readableTime($elapsedMs);
    }

    /**
     * `assertUrlHasProtocol(url, protocol)` (exits through {@see ExitError}).
     *
     * @param string|list<string>|null $protocol e.g. `['https:', 'http:']`
     */
    public static function assertUrlHasProtocol(string $url, string|array|null $protocol = null): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $urlProtocol = is_string($scheme) && $scheme !== '' ? strtolower($scheme) . ':' : '';

        if ($urlProtocol === '') {
            throw new ExitError(1, "{$url} does not have a protocol");
        }

        // if just checking for the existence of a protocol, return
        if ($protocol === null) {
            return;
        }

        if (is_string($protocol)) {
            if ($protocol !== $urlProtocol) {
                throw new ExitError(1, "{$url} must have the protocol {$protocol}");
            }

            return;
        }

        // assume an array
        if (!in_array($urlProtocol, $protocol, true)) {
            throw new ExitError(1, "{$url} must have one of the following protocols: " . implode(',', $protocol));
        }
    }
}
