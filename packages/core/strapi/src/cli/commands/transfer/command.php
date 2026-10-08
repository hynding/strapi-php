<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Transfer;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Strapi\Cli\Cli\Utils\Commander;
use Strapi\Cli\Cli\Utils\DataTransfer;
use Strapi\Cli\Cli\Utils\ExitError;
use Strapi\Cli\Cli\Utils\Helpers;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Port of packages/core/strapi/src/cli/commands/transfer/command.ts: `$ strapi transfer`.
 */
final class Command extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('transfer')
            ->setDescription('Transfer data from one source to another')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'URL of the remote Strapi instance to get data from')
            ->addOption('from-token', null, InputOption::VALUE_REQUIRED, 'Transfer token for the remote Strapi source')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'URL of the remote Strapi instance to send data to')
            ->addOption('to-token', null, InputOption::VALUE_REQUIRED, 'Transfer token for the remote Strapi destination')
            ->addOption('checksums', null, InputOption::VALUE_NEGATABLE, 'Disable end-to-end asset checksum verification for assets transfer (--no-checksums)')
            ->addOption('force', null, InputOption::VALUE_NONE, Commander::FORCE_OPTION_DESCRIPTION)
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED, DataTransfer::excludeOptionDescription())
            ->addOption('only', null, InputOption::VALUE_REQUIRED, DataTransfer::onlyOptionDescription())
            ->addOption('exclude-content-types', null, InputOption::VALUE_REQUIRED, DataTransfer::excludeContentTypesOptionDescription())
            ->addOption('only-content-types', null, InputOption::VALUE_REQUIRED, DataTransfer::ONLY_CONTENT_TYPES_OPTION_DESCRIPTION)
            ->addOption('throttle', null, InputOption::VALUE_REQUIRED, DataTransfer::THROTTLE_OPTION_DESCRIPTION);
    }

    /**
     * The option values after the `preAction` hooks (including the interactive prompts).
     *
     * @return array<string, mixed>
     */
    public static function resolveOptions(InputInterface $input, OutputInterface $output): array
    {
        $from = $input->getOption('from');
        $to = $input->getOption('to');

        $opts = [
            'from' => is_string($from) ? Commander::parseURL($from) : null,
            'fromToken' => $input->getOption('from-token'),
            'to' => is_string($to) ? Commander::parseURL($to) : null,
            'toToken' => $input->getOption('to-token'),
            'checksums' => $input->getOption('checksums') ?? true,
            'verbose' => $output->isVerbose(),
            'force' => (bool) $input->getOption('force'),
            ...DataTransfer::parseFilterOptions($input),
        ];

        DataTransfer::normalizeTransferFilterOptions($opts);
        DataTransfer::validateExcludeOnly($opts);
        DataTransfer::validateContentTypeTransferOptions($opts);

        if (($opts['from'] && $opts['to']) || ($opts['from'] && $opts['toToken']) || ($opts['to'] && $opts['fromToken'])) {
            throw new ExitError(1, 'Only one remote source (from) or destination (to) option may be provided');
        }

        // Only run interactive prompts if neither --from nor --to is provided
        if (!$opts['from'] && !$opts['to']) {
            self::promptForRemote($opts, $input, $output);
        }

        // If --from is used, validate the URL and token
        if ($opts['from']) {
            Helpers::assertUrlHasProtocol((string) $opts['from'], ['https:', 'http:']);
            if (empty($opts['fromToken'])) {
                $token = Commander::promptPassword('Please enter your transfer token for the remote Strapi source', $input, $output);
                if ($token === null || $token === '') {
                    throw new ExitError(1, 'No token provided for remote source, aborting transfer.');
                }
                $opts['fromToken'] = $token;
            }

            Commander::getCommanderConfirmMessage(
                'The transfer will delete all the local Strapi assets and its database. Are you sure you want to proceed?',
                'Transfer process aborted',
                (bool) $opts['force'],
                $input,
                $output
            );
        }

        // If --to is used, validate the URL, token, and confirm restore
        if ($opts['to']) {
            Helpers::assertUrlHasProtocol((string) $opts['to'], ['https:', 'http:']);
            if (empty($opts['toToken'])) {
                $token = Commander::promptPassword('Please enter your transfer token for the remote Strapi destination', $input, $output);
                if ($token === null || $token === '') {
                    throw new ExitError(1, 'No token provided for remote destination, aborting transfer.');
                }
                $opts['toToken'] = $token;
            }

            Commander::getCommanderConfirmMessage(
                'The transfer will delete existing data from the remote Strapi! Are you sure you want to proceed?',
                'Transfer process aborted',
                (bool) $opts['force'],
                $input,
                $output
            );
        }

        return $opts;
    }

    /** @param array<string, mixed> $opts */
    private static function promptForRemote(array &$opts, InputInterface $input, OutputInterface $output): void
    {
        $hasEnvUrl = getenv('STRAPI_TRANSFER_URL') ?: null;
        $hasEnvToken = getenv('STRAPI_TRANSFER_TOKEN') ?: null;
        $helper = new QuestionHelper();

        // If user has not provided a direction from CLI, log the documentation
        $output->writeln('ℹ️  Data transfer documentation: https://docs.strapi.io/dev-docs/data-management/transfer');

        if (!$hasEnvUrl && !$hasEnvToken) {
            $output->writeln('ℹ️  No transfer configuration found in environment variables');
            $output->writeln('   → Add STRAPI_TRANSFER_URL and STRAPI_TRANSFER_TOKEN environment variables to make the transfer process faster for future runs');
        } else {
            $output->writeln('ℹ️  Found transfer configuration in your environment:');

            if ($hasEnvUrl) {
                $output->writeln("   → Environment STRAPI_TRANSFER_URL ({$hasEnvUrl}) will be used as the transfer URL");
            }

            if ($hasEnvToken) {
                $output->writeln('   → Environment STRAPI_TRANSFER_TOKEN value will be used as the transfer token');
            }

            $output->writeln(''); // Empty line for better readability
        }

        $choices = [
            'from' => 'Pull data from remote Strapi to local',
            'to' => 'Push local data to remote Strapi',
        ];
        $answer = $helper->ask($input, $output, new ChoiceQuestion('Choose transfer direction:', array_values($choices)));
        $direction = array_search($answer, $choices, true);
        if (!is_string($direction)) {
            throw new ExitError(1, 'Transfer process aborted');
        }

        // URL
        if ($hasEnvUrl) {
            $remoteUrl = Commander::parseURL($hasEnvUrl);
        } else {
            $question = new Question('Enter the URL of the remote Strapi instance to ' . ($direction === 'from' ? 'get data from' : 'send data to') . ': ');
            $question->setValidator(static function (mixed $value): string {
                $url = is_string($value) ? parse_url($value) : false;
                if ($url === false || empty($url['host']) || empty($url['scheme'])) {
                    throw new \RuntimeException('Please enter a valid URL (e.g., http://localhost:1337/admin or https://example.com/admin)');
                }
                if (!in_array(strtolower((string) $url['scheme']), ['http', 'https'], true)) {
                    throw new \RuntimeException('URL must use http: or https: protocol');
                }

                return (string) $value;
            });
            $remoteUrl = (string) $helper->ask($input, $output, $question);
        }
        $opts[$direction] = $remoteUrl;

        // Token
        if (!empty($opts["{$direction}Token"])) {
            return;
        }
        if ($hasEnvToken) {
            $opts["{$direction}Token"] = $hasEnvToken;

            return;
        }
        $question = (new Question('Enter the transfer token for the remote Strapi ' . ($direction === 'from' ? 'source' : 'destination') . ': '))
            ->setHidden(true)
            ->setHiddenFallback(false)
            ->setValidator(static function (mixed $value): string {
                if (!is_string($value) || $value === '') {
                    throw new \RuntimeException('Transfer token is required');
                }

                return $value;
            });
        $opts["{$direction}Token"] = $helper->ask($input, $output, $question);
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
