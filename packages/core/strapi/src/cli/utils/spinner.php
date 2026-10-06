<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Utils;

/** Not an upstream file: the `ora` subset the CLI logger exposes (`spinner(text)`), as plain lines. */
final class Spinner
{
    public string $text;

    public bool $isSpinning = false;

    public function __construct(private readonly Logger $logger, string $text)
    {
        $this->text = $text;
    }

    public function start(?string $text = null): static
    {
        if ($text !== null) {
            $this->text = $text;
        }
        $this->isSpinning = true;
        $this->logger->log('⠋ ' . $this->text);

        return $this;
    }

    public function succeed(?string $text = null): static
    {
        $this->isSpinning = false;
        $this->logger->log(Logger::colorize('✔', 'green') . ' ' . ($text ?? $this->text));

        return $this;
    }

    public function fail(?string $text = null): static
    {
        $this->isSpinning = false;
        $this->logger->log(Logger::colorize('✖', 'red') . ' ' . ($text ?? $this->text));

        return $this;
    }
}
