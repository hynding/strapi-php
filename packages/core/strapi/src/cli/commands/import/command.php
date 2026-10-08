<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Import;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Strapi\Cli\Cli\Utils\Commander;
use Strapi\Cli\Cli\Utils\DataTransfer;
use Strapi\Cli\Cli\Utils\ExitError;
use Strapi\Cli\Cli\Utils\Helpers;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/import/command.ts: `$ strapi import`.
 */
final class Command extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('import')
            ->setDescription('Import data from file to Strapi')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'path to a Strapi export (.tar[.gz][.enc]) or to an unpacked export directory')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Provide encryption key in command instead of using the prompt')
            ->addOption('force', null, InputOption::VALUE_NONE, Commander::FORCE_OPTION_DESCRIPTION)
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED, DataTransfer::excludeOptionDescription())
            ->addOption('only', null, InputOption::VALUE_REQUIRED, DataTransfer::onlyOptionDescription())
            ->addOption('exclude-content-types', null, InputOption::VALUE_REQUIRED, DataTransfer::excludeContentTypesOptionDescription())
            ->addOption('only-content-types', null, InputOption::VALUE_REQUIRED, DataTransfer::ONLY_CONTENT_TYPES_OPTION_DESCRIPTION)
            ->addOption('throttle', null, InputOption::VALUE_REQUIRED, DataTransfer::THROTTLE_OPTION_DESCRIPTION);
    }

    /** node `path.extname()` */
    private static function extname(string $file): string
    {
        $base = basename($file);
        $dot = strrpos($base, '.');

        return $dot === false || $dot === 0 ? '' : substr($base, $dot);
    }

    /** node `path.parse(file).name` with the directory kept (upstream strips one extension) */
    private static function trimExtension(string $file): string
    {
        $ext = self::extname($file);

        return $ext === '' ? $file : substr($file, 0, -strlen($ext));
    }

    /**
     * The option values after the `preAction` hooks.
     *
     * @return array<string, mixed>
     */
    public static function resolveOptions(InputInterface $input, OutputInterface $output): array
    {
        $file = $input->getOption('file');
        if (!is_string($file) || $file === '') {
            throw new ExitError(1, "error: required option '-f, --file <file>' not specified");
        }

        $opts = [
            'file' => $file,
            'key' => $input->getOption('key'),
            'verbose' => $output->isVerbose(),
            'force' => (bool) $input->getOption('force'),
            ...DataTransfer::parseFilterOptions($input),
        ];

        DataTransfer::normalizeTransferFilterOptions($opts);
        DataTransfer::validateExcludeOnly($opts);
        DataTransfer::validateContentTypeTransferOptions($opts);

        // check extension to guess if we should prompt for key
        if (self::extname($file) === '.enc') {
            if (empty($opts['key'])) {
                $key = Commander::promptPassword('Please enter your decryption key', $input, $output);
                if ($key === null || $key === '') {
                    throw new ExitError(1, 'No key entered, aborting import.');
                }
                $opts['key'] = $key;
            }
        }

        // set decrypt and decompress options based on filename (archive only)
        if (is_dir($file)) {
            $opts['decrypt'] = false;
            $opts['decompress'] = false;
        } else {
            $current = $file;

            if (self::extname($current) === '.enc') {
                $current = self::trimExtension($current); // trim the .enc extension
                $opts['decrypt'] = true;
            } else {
                $opts['decrypt'] = false;
            }

            if (self::extname($current) === '.gz') {
                $current = self::trimExtension($current); // trim the .gz extension
                $opts['decompress'] = true;
            } else {
                $opts['decompress'] = false;
            }

            if (self::extname($current) !== '.tar') {
                throw new ExitError(1, "The file '{$file}' does not appear to be a valid Strapi data file. Use a path ending in .tar[.gz][.enc], or an existing directory that contains an unpacked export (e.g. metadata.json).");
            }
        }

        Commander::getCommanderConfirmMessage(
            'The import will delete your existing data! Are you sure you want to proceed?',
            'Import process aborted',
            (bool) $opts['force'],
            $input,
            $output
        );

        return $opts;
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        try {
            $opts = self::resolveOptions($input, $output);
        } catch (ExitError $exit) {
            return Helpers::exitWith($exit->exitCode, $exit->messages, $output);
        }

        return (new Action(['cwd' => $this->ctx->cwd]))($opts, $input, $output);
    }
}
