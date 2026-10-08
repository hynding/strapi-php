<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Utils;

/**
 * Not an upstream file: upstream's `exitWith(code, message)` ends the process from anywhere
 * (`process.exit`); the commands that need that from deep inside (hooks, prompts, the transfer
 * actions) throw this instead, and the command prints the message(s) and returns the code.
 */
final class ExitError extends \RuntimeException
{
    /** @var list<string> */
    public readonly array $messages;

    /** @param string|list<string>|null $message */
    public function __construct(public readonly int $exitCode, string|array|null $message = null)
    {
        $this->messages = is_string($message) ? [$message] : ($message ?? []);
        parent::__construct(implode("\n", $this->messages), $exitCode);
    }
}
