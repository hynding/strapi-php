<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Export;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Strapi\Cli\Cli\Utils\Commander;
use Strapi\Cli\Cli\Utils\DataTransfer;
use Strapi\Cli\Cli\Utils\ExitError;
use Strapi\Cli\Cli\Utils\Helpers;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/export/command.ts: `$ strapi export`.
 *
 * `--verbose` is symfony/console's global option (`-v`).
 */
final class Command extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('export')
            ->setDescription('Export data from Strapi to file')
            ->addOption('encrypt', null, InputOption::VALUE_NEGATABLE, "Disables 'aes-128-ecb' encryption of the output file (--no-encrypt)")
            ->addOption('compress', null, InputOption::VALUE_NEGATABLE, 'Disables gzip compression of output file (--no-compress)')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Provide encryption key in command instead of using the prompt')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'tar: base name without extensions; dir: output directory path (--format dir)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'export as tar archive or unpacked directory (choices: "tar", "dir")', 'tar')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED, DataTransfer::excludeOptionDescription())
            ->addOption('only', null, InputOption::VALUE_REQUIRED, DataTransfer::onlyOptionDescription())
            ->addOption('exclude-content-types', null, InputOption::VALUE_REQUIRED, DataTransfer::excludeContentTypesOptionDescription())
            ->addOption('only-content-types', null, InputOption::VALUE_REQUIRED, DataTransfer::ONLY_CONTENT_TYPES_OPTION_DESCRIPTION)
            ->addOption('throttle', null, InputOption::VALUE_REQUIRED, DataTransfer::THROTTLE_OPTION_DESCRIPTION);
    }

    /**
     * The option values after the `preAction` hooks.
     *
     * @return array<string, mixed>
     */
    public static function resolveOptions(InputInterface $input, OutputInterface $output): array
    {
        $format = $input->getOption('format');
        if (!in_array($format, ['tar', 'dir'], true)) {
            throw new ExitError(1, "error: option '--format <format>' argument '" . (is_scalar($format) ? (string) $format : '') . "' is invalid. Allowed choices are tar, dir.");
        }

        $encryptCli = $input->getOption('encrypt');
        $compressCli = $input->getOption('compress');

        $opts = [
            'encrypt' => $encryptCli ?? true,
            'compress' => $compressCli ?? true,
            'verbose' => $output->isVerbose(),
            'key' => $input->getOption('key'),
            'file' => $input->getOption('file'),
            'format' => $format,
            ...DataTransfer::parseFilterOptions($input),
        ];

        DataTransfer::normalizeTransferFilterOptions($opts);
        DataTransfer::validateExcludeOnly($opts);
        DataTransfer::validateContentTypeTransferOptions($opts);
        ValidateDirFormat::prepareExportDirFormatCli($opts, is_bool($encryptCli) ? $encryptCli : null);
        Commander::promptEncryptionKey($opts, $input, $output);

        return $opts;
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        try {
            $opts = self::resolveOptions($input, $output);
        } catch (ExitError $exit) {
            return Helpers::exitWith($exit->exitCode, $exit->messages, $output);
        }

        return (new Action(['cwd' => $this->ctx->cwd]))($opts, $output);
    }
}
