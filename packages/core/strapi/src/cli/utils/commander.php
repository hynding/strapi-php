<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Utils;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Port of packages/core/strapi/src/cli/utils/commander.ts: option parsers and hooks shared by the
 * data-transfer commands. Commander's `argParser`s become functions applied to the raw option
 * values; `exitWith` becomes {@see ExitError}; inquirer prompts are symfony/console questions.
 */
final class Commander
{
    public const string FORCE_OPTION_DESCRIPTION = 'Automatically answer "yes" to all prompts, including potentially destructive requests, and run non-interactively.';

    /**
     * argParser: Parse a comma-delimited string as an array
     *
     * @return list<string>
     */
    public static function parseList(string $value): array
    {
        // trim shouldn't be necessary but might help catch unexpected whitespace characters
        return array_map('trim', explode(',', $value));
    }

    /**
     * Returns an argParser that returns a list
     *
     * @param list<string> $choices
     *
     * @return \Closure(string): list<string>
     */
    public static function getParseListWithChoices(array $choices, string $errorMessage = 'Invalid options:'): \Closure
    {
        return static function (string $value) use ($choices, $errorMessage): array {
            $list = self::parseList($value);
            $invalid = array_values(array_filter($list, static fn (string $item): bool => !in_array($item, $choices, true)));

            if ($invalid !== []) {
                throw new ExitError(1, "{$errorMessage}: " . implode(',', $invalid));
            }

            return $list;
        };
    }

    /**
     * argParser: Parse a string as an integer
     */
    public static function parseInteger(string $value): int
    {
        if (preg_match('/^\s*[+-]?\d+/', $value, $m) !== 1) {
            throw new ExitError(1, "error: option argument is invalid. Not an integer: {$value}");
        }

        return (int) $m[0];
    }

    /**
     * argParser: Parse a string as a URL
     */
    public static function parseURL(string $value): string
    {
        $url = parse_url($value);
        if ($url === false || empty($url['host']) || empty($url['scheme'])) {
            throw new ExitError(1, "error: option argument is invalid. Could not parse url {$value}");
        }

        return $value;
    }

    /**
     * hook: if encrypt==true and key not provided, prompt for it
     *
     * @param array<string, mixed> $opts
     */
    public static function promptEncryptionKey(array &$opts, InputInterface $input, OutputInterface $output): void
    {
        $key = $opts['key'] ?? null;
        if (empty($opts['encrypt']) && is_string($key) && $key !== '') {
            throw new ExitError(1, 'Key may not be present unless encryption is used');
        }

        // if encrypt==true but we have no key, prompt for it
        if (!empty($opts['encrypt']) && !(is_string($key) && $key !== '')) {
            try {
                $question = (new Question('Please enter an encryption key '))
                    ->setHidden(true)
                    ->setHiddenFallback(false)
                    ->setValidator(static function (mixed $answer): string {
                        if (is_string($answer) && $answer !== '') {
                            return $answer;
                        }

                        throw new \RuntimeException('Key must be present when using the encrypt option');
                    })
                    ->setMaxAttempts(3);
                $opts['key'] = (new QuestionHelper())->ask($input, $output, $question);
            } catch (\Throwable) {
                throw new ExitError(1, 'Failed to get encryption key');
            }
            if (!is_string($opts['key']) || $opts['key'] === '') {
                throw new ExitError(1, 'Failed to get encryption key');
            }
        }
    }

    /** A hidden answer (inquirer `type: 'password'`); null when nothing could be read. */
    public static function promptPassword(string $message, InputInterface $input, OutputInterface $output): ?string
    {
        try {
            $answer = (new QuestionHelper())->ask($input, $output, (new Question("{$message} "))->setHidden(true)->setHiddenFallback(false));
        } catch (\Throwable) {
            return null;
        }

        return is_string($answer) ? $answer : null;
    }

    /**
     * hook: require a confirmation message to be accepted unless forceOption (--force) is used
     */
    public static function getCommanderConfirmMessage(string $message, ?string $failMessage, bool $force, InputInterface $input, OutputInterface $output): void
    {
        $confirmed = self::confirmMessage($message, $force, $input, $output);
        if (!$confirmed) {
            throw new ExitError(1, $failMessage);
        }
    }

    public static function confirmMessage(string $message, bool $force, InputInterface $input, OutputInterface $output): bool
    {
        // if we have a force option, respond yes
        if ($force) {
            // attempt to mimic the inquirer prompt exactly
            $output->writeln(Logger::colorize('?', 'green') . ' ' . Logger::colorize($message, 'bold') . ' ' . Logger::colorize('Yes', 'cyan'));

            return true;
        }

        $answer = (new QuestionHelper())->ask(
            $input,
            $output,
            new ConfirmationQuestion(Logger::colorize('?', 'green') . " {$message} (y/N) ", false)
        );

        return $answer === true;
    }
}
