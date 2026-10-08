<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/cli/create-strapi-app/src/utils/logger.ts. chalk becomes Symfony console
 * styles; `fatal()` prints the message and throws {@see FatalError} (upstream: `process.exit(1)`),
 * which the command turns into exit code 1.
 */
final class Logger
{
    private const MAX_PREFIX_LENGTH = 8;

    public function __construct(private readonly OutputInterface $output)
    {
    }

    public function output(): OutputInterface
    {
        return $this->output;
    }

    private static function badge(string $text, string $bgColor, string $textColor = 'black'): string
    {
        $wrappedText = " {$text} ";
        $repeat = max(0, self::MAX_PREFIX_LENGTH - strlen($wrappedText));

        return str_repeat(' ', $repeat) . "<fg={$textColor};bg={$bgColor}>" . OutputFormatter::escape($wrappedText) . '</>';
    }

    /** @param string|list<string> $text */
    private static function textIndent(string|array $text, bool $indentFirst = true, int $indent = self::MAX_PREFIX_LENGTH + 2): string
    {
        $parts = is_array($text) ? $text : [$text];
        $lines = [];
        foreach ($parts as $i => $part) {
            $lines[] = ($i === 0 && !$indentFirst) ? $part : str_repeat(' ', $indent) . $part;
        }

        return implode("\n", $lines);
    }

    /** @param string|list<string> $message */
    public function log(string|array $message): void
    {
        $this->output->writeln(self::textIndent($message));
    }

    public function title(string $title, string $message): void
    {
        $prefix = self::badge($title, 'bright-blue');
        $this->output->writeln("\n{$prefix}  {$message}");
    }

    public function info(string $message): void
    {
        $this->output->writeln(str_repeat(' ', 7) . "<fg=cyan>●</>  {$message}");
    }

    public function success(string $message): void
    {
        $this->output->writeln("\n" . str_repeat(' ', 7) . "<fg=green>✓</>  <fg=green>{$message}</>");
    }

    /** @param string|list<string>|null $message */
    public function fatal(string|array|null $message = null): never
    {
        if ($message !== null && $message !== '' && $message !== []) {
            $this->error($message);
        }

        throw new FatalError(is_array($message) ? implode("\n", $message) : (string) $message);
    }

    /** @param string|list<string> $message */
    public function error(string|array $message): void
    {
        $prefix = self::badge('Error', 'red');
        $this->errorOutput()->writeln("\n{$prefix}  " . self::textIndent($message, false) . "\n");
    }

    /** @param string|list<string> $message */
    public function warn(string|array $message): void
    {
        $prefix = self::badge('Warn', 'yellow');
        $this->errorOutput()->writeln("\n{$prefix}  " . self::textIndent($message, false) . "\n");
    }

    private function errorOutput(): OutputInterface
    {
        return $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
    }
}
