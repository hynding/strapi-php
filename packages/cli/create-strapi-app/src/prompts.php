<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Port of packages/cli/create-strapi-app/src/prompts.ts: inquirer becomes Symfony's QuestionHelper.
 * Same questions and defaults. Upstream's `typescript()` prompt is not ported (the server is PHP).
 */
final class Prompts
{
    public function __construct(
        private readonly InputInterface $input,
        private readonly OutputInterface $output,
        private readonly QuestionHelper $helper = new QuestionHelper(),
    ) {
    }

    public function directory(): string
    {
        return $this->input('What is the name of your project?', 'my-strapi-project') ?? 'my-strapi-project';
    }

    public function example(): bool
    {
        return $this->confirm('Start with an example structure & data?', false);
    }

    public function gitInit(): bool
    {
        return $this->confirm('Initialize a git repository?', true);
    }

    public function installDependencies(string $packageManager): bool
    {
        return $this->confirm("Install dependencies with composer and {$packageManager}?", true);
    }

    public function confirm(string $message, bool $default): bool
    {
        $question = new ConfirmationQuestion(self::label($message, $default ? 'Y/n' : 'y/N'), $default);

        return (bool) $this->helper->ask($this->input, $this->output, $question);
    }

    /** @param (callable(?string): ?string)|null $validate */
    public function input(string $message, ?string $default = null, ?callable $validate = null): ?string
    {
        $question = new Question(self::label($message, $default), $default);
        if ($validate !== null) {
            $question->setValidator($validate);
        }
        $answer = $this->helper->ask($this->input, $this->output, $question);

        return is_scalar($answer) ? (string) $answer : null;
    }

    public function password(string $message): ?string
    {
        $question = (new Question(self::label($message, null)))->setHidden(true)->setHiddenFallback(true);
        $answer = $this->helper->ask($this->input, $this->output, $question);

        return is_scalar($answer) ? (string) $answer : null;
    }

    /** @param list<string> $choices */
    public function select(string $message, array $choices, string $default): string
    {
        $question = new ChoiceQuestion(self::label($message, null), $choices, $default);
        $answer = $this->helper->ask($this->input, $this->output, $question);

        return is_string($answer) ? $answer : $default;
    }

    private static function label(string $message, ?string $default): string
    {
        return '<fg=green>?</> <options=bold>' . $message . '</>' . ($default !== null && $default !== '' ? " <fg=gray>({$default})</>" : '') . ' ';
    }
}
