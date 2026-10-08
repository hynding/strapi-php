<?php

declare(strict_types=1);

namespace Strapi\Generators;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question as SymfonyQuestion;

/**
 * Not an upstream file: {@see Inquirer} on symfony/console's question helper (inquirer upstream).
 *
 * Answers given up front (plop's bypass: positional answers and `--name=value`, see
 * `strapi generate --help`) are used instead of asking; they are converted (`confirm`: y/yes/true/1
 * or n/no/false/0, `list`: a choice value or name) and validated like typed answers. Without a TTY
 * (`--no-interaction`) a question without an answer takes its default, or fails when it has none.
 *
 * @phpstan-import-type Question from Plop
 */
final class ConsoleInquirer implements Inquirer
{
    /** @param array<string, mixed> $presets answers given up front, keyed by question name */
    public function __construct(
        private readonly InputInterface $input,
        private readonly OutputInterface $output,
        private readonly QuestionHelper $helper,
        private array $presets = [],
    ) {
    }

    /** @param array<string, mixed> $presets */
    public function addPresets(array $presets): void
    {
        $this->presets = [...$this->presets, ...$presets];
    }

    public function hasPreset(string $name): bool
    {
        return array_key_exists($name, $this->presets);
    }

    public function preset(string $name): mixed
    {
        return $this->presets[$name] ?? null;
    }

    public function warn(string $message): void
    {
        $this->output->writeln("<comment>{$message}</comment>");
    }

    public function prompt(array $questions): array
    {
        $answers = [];

        foreach ($questions as $question) {
            $when = $question['when'] ?? null;
            if ($when !== null && !$when($answers)) {
                continue;
            }

            $answers[$question['name']] = $this->answer($question, $answers);
        }

        return $answers;
    }

    /**
     * @param Question $question
     * @param array<string, mixed> $answers
     */
    private function answer(array $question, array $answers): mixed
    {
        $name = $question['name'];
        $default = $question['default'] ?? null;
        if (is_callable($default) && !is_string($default)) {
            $default = $default($answers);
        }

        if (array_key_exists($name, $this->presets)) {
            return $this->validate($question, $this->convert($question, $this->presets[$name], $answers), $answers);
        }

        if (!$this->input->isInteractive()) {
            if ($default === null && $question['type'] !== 'confirm') {
                throw new \RuntimeException("Missing answer for \"{$name}\" ({$question['message']}): pass it as --{$name}=<value>");
            }

            return $this->validate($question, $this->convert($question, $default ?? true, $answers), $answers);
        }

        return $this->helper->ask($this->input, $this->output, $this->toSymfonyQuestion($question, $default, $answers));
    }

    /**
     * @param Question $question
     * @param array<string, mixed> $answers
     */
    private function toSymfonyQuestion(array $question, mixed $default, array $answers): SymfonyQuestion
    {
        $message = "<info>?</info> {$question['message']}";

        if ($question['type'] === 'confirm') {
            $default = $default === null ? true : (bool) $default;

            return new ConfirmationQuestion($message . ($default ? ' (Y/n) ' : ' (y/N) '), $default);
        }

        if ($question['type'] === 'list') {
            $choices = self::choices($question, $answers);
            $names = array_map(static fn (array $c): string => $c['name'], $choices);
            $defaultName = null;
            foreach ($choices as $index => $choice) {
                if ($default === $choice['value'] || $default === $index) {
                    $defaultName = $choice['name'];
                }
            }

            $symfonyQuestion = new ChoiceQuestion($message, $names, $defaultName);
            $symfonyQuestion->setValidator(function (mixed $selected) use ($question, $choices, $answers): mixed {
                return $this->validate($question, $this->choiceValue($choices, $selected, $question['name']), $answers);
            });

            return $symfonyQuestion;
        }

        $symfonyQuestion = new SymfonyQuestion($message . (is_scalar($default) ? " ({$default}) " : ' '), is_scalar($default) ? (string) $default : null);
        $symfonyQuestion->setValidator(fn (mixed $value): mixed => $this->validate($question, $value ?? '', $answers));

        return $symfonyQuestion;
    }

    /**
     * @param Question $question
     * @param array<string, mixed> $answers
     */
    private function convert(array $question, mixed $value, array $answers): mixed
    {
        return match ($question['type']) {
            'confirm' => self::toBool($value, $question['name']),
            'list' => $this->choiceValue(self::choices($question, $answers), $value, $question['name']),
            default => $value,
        };
    }

    private static function toBool(mixed $value, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return match ($normalized) {
            'y', 'yes', 'true', '1' => true,
            'n', 'no', 'false', '0', '' => false,
            default => throw new \RuntimeException("Invalid answer for \"{$name}\": expected yes or no"),
        };
    }

    /** @param list<array{name: string, value: mixed}> $choices */
    private function choiceValue(array $choices, mixed $selected, string $name): mixed
    {
        foreach ($choices as $choice) {
            if ($choice['value'] === $selected) {
                return $choice['value'];
            }
        }
        foreach ($choices as $choice) {
            if ($choice['name'] === $selected || (is_scalar($choice['value']) && !is_bool($choice['value']) && is_scalar($selected) && (string) $choice['value'] === (string) $selected)) {
                return $choice['value'];
            }
        }
        // the choice's index, as listed by the question helper
        if ((is_int($selected) || (is_string($selected) && ctype_digit($selected))) && isset($choices[(int) $selected])) {
            return $choices[(int) $selected]['value'];
        }

        $label = is_scalar($selected) ? (string) $selected : get_debug_type($selected);
        $valid = implode(', ', array_map(static fn (array $c): string => is_scalar($c['value']) ? (string) $c['value'] : $c['name'], $choices));

        throw new \RuntimeException("Invalid answer \"{$label}\" for \"{$name}\": expected one of {$valid}");
    }

    /**
     * @param Question $question
     * @param array<string, mixed> $answers
     * @return list<array{name: string, value: mixed}>
     */
    public static function choices(array $question, array $answers): array
    {
        $choices = $question['choices'] ?? [];
        if (is_callable($choices)) {
            $choices = $choices($answers);
        }

        $out = [];
        foreach (is_array($choices) ? $choices : [] as $choice) {
            if (is_array($choice)) {
                $value = array_key_exists('value', $choice) ? $choice['value'] : ($choice['name'] ?? null);
                $out[] = ['name' => (string) ($choice['name'] ?? (is_scalar($value) ? $value : '')), 'value' => $value];
            } elseif (is_scalar($choice)) {
                $out[] = ['name' => (string) $choice, 'value' => $choice];
            }
        }

        return $out;
    }

    /**
     * @param Question $question
     * @param array<string, mixed> $answers
     */
    private function validate(array $question, mixed $value, array $answers): mixed
    {
        $validate = $question['validate'] ?? null;
        if ($validate === null) {
            return $value;
        }

        $result = $validate($value, $answers);
        if ($result !== true) {
            throw new \RuntimeException(is_string($result) ? $result : "Invalid answer for \"{$question['name']}\"");
        }

        return $value;
    }
}
