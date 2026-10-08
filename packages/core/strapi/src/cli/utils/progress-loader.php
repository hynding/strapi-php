<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Utils;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not an upstream file: one stage's progress line (the `ora` spinner of the data-transfer
 * commands). On a terminal the line is redrawn in place (at most ten times a second); otherwise
 * only the start and the final line are printed.
 */
final class ProgressLoader
{
    public string $text = '';

    public bool $isSpinning = false;

    private float $lastRender = 0.0;

    private const array FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private int $frame = 0;

    public function __construct(private readonly ?OutputInterface $output = null)
    {
    }

    private function interactive(): bool
    {
        return $this->output !== null && $this->output->isDecorated();
    }

    private function write(string $line, bool $newline): void
    {
        $output = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
        if ($output === null) {
            return;
        }

        if ($this->interactive()) {
            $output->write("\r\033[2K" . $line . ($newline ? "\n" : ''), false, OutputInterface::OUTPUT_RAW);
        } elseif ($newline) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }
    }

    public function setText(string $text): self
    {
        $this->text = $text;

        if ($this->isSpinning && $this->interactive() && microtime(true) - $this->lastRender >= 0.1) {
            $this->lastRender = microtime(true);
            $this->frame = ($this->frame + 1) % count(self::FRAMES);
            $this->write(self::FRAMES[$this->frame] . ' ' . $this->text, false);
        }

        return $this;
    }

    public function start(?string $text = null): self
    {
        if ($text !== null) {
            $this->text = $text;
        }
        $this->isSpinning = true;
        $this->lastRender = microtime(true);
        if ($this->interactive()) {
            $this->write(self::FRAMES[0] . ' ' . $this->text, false);
        }

        return $this;
    }

    public function succeed(?string $text = null): self
    {
        $this->isSpinning = false;
        $this->write(Logger::colorize('✔', 'green') . ' ' . ($text ?? $this->text), true);

        return $this;
    }

    public function fail(?string $text = null): self
    {
        $this->isSpinning = false;
        $this->write(Logger::colorize('✖', 'red') . ' ' . ($text ?? $this->text), true);

        return $this;
    }
}
