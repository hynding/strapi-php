<?php

declare(strict_types=1);

namespace Strapi\Generators;

/**
 * Not an upstream file: the `inquirer.prompt()` contract the generators' prompts use. Questions
 * are inquirer-style arrays (`type` input|confirm|list, `name`, `message`, `default` (a value or
 * `callable(array $answers)`), `choices` (a list of strings or `['name', 'value']`, or a
 * `callable(array $answers)` returning one), `when` and `validate` (returns true or an error message)).
 *
 * @phpstan-import-type Question from Plop
 */
interface Inquirer
{
    /**
     * @param list<Question> $questions
     * @return array<string, mixed> answers keyed by question name
     */
    public function prompt(array $questions): array;

    /** Upstream's `console.warn()` while prompting. */
    public function warn(string $message): void;
}
