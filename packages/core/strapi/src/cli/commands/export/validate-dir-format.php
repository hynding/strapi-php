<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Export;

use Strapi\Cli\Cli\Utils\ExitError;

/**
 * Port of packages/core/strapi/src/cli/commands/export/validate-dir-format.ts.
 */
final class ValidateDirFormat
{
    public const string EXPORT_DIR_REQUIRES_NO_ENCRYPT = 'Unpacked directory exports (--format dir) require --no-encrypt.';

    public const string EXPORT_DIR_ENCRYPTION_NOT_SUPPORTED = 'Unpacked directory exports (--format dir) do not support encryption. Use --format tar, or omit --encrypt.';

    /**
     * Directory exports require an explicit `--no-encrypt` (security). Compression is tar-only and is
     * turned off automatically for `--format dir` (no `--no-compress` needed). Runs before
     * `promptEncryptionKey` so missing `--no-encrypt` fails with a clear message instead of a key prompt.
     *
     * @param array<string, mixed> $opts      the option values (`encrypt`, `compress`, `format`)
     * @param bool|null            $encryptCli the `--encrypt` / `--no-encrypt` flag as given on the command line (null: not given)
     */
    public static function prepareExportDirFormatCli(array &$opts, ?bool $encryptCli): void
    {
        $format = $opts['format'] ?? 'tar';
        if ($format !== 'dir') {
            return;
        }

        if ($encryptCli === true) {
            throw new ExitError(1, self::EXPORT_DIR_ENCRYPTION_NOT_SUPPORTED);
        }

        $explicitNoEncrypt = $encryptCli === false;

        if (!$explicitNoEncrypt) {
            throw new ExitError(1, self::EXPORT_DIR_REQUIRES_NO_ENCRYPT);
        }

        $opts['compress'] = false;
    }

    /**
     * Same rules for programmatic `exportAction(opts)` (no Commander hooks).
     *
     * @param array<string, mixed> $opts
     */
    public static function normalizeExportDirFormatOpts(array &$opts): void
    {
        $format = $opts['format'] ?? 'tar';
        if ($format !== 'dir') {
            return;
        }

        if (($opts['encrypt'] ?? null) === true) {
            throw new ExitError(1, self::EXPORT_DIR_ENCRYPTION_NOT_SUPPORTED);
        }

        if (($opts['encrypt'] ?? null) !== false) {
            throw new ExitError(1, self::EXPORT_DIR_REQUIRES_NO_ENCRYPT);
        }

        $opts['compress'] = false;
    }
}
