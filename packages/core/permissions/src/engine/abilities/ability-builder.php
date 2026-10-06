<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Abilities;

/** The slice of CASL's `AbilityBuilder` Strapi uses: `can()` to register raw rules, `build()` to get the Ability. */
final class AbilityBuilder
{
    /** @var list<Rule> */
    private array $rules = [];

    /**
     * @param string|list<string> $subject
     * @param list<string>|null $fields
     * @param array<string, mixed>|null $conditions
     * @param list<string> $operations
     */
    public function can(string $action, string|array $subject = 'all', ?array $fields = null, ?array $conditions = null, array $operations = Sift::ALLOWED_OPERATIONS): self
    {
        foreach ((array) $subject as $one) {
            $this->rules[] = new Rule($action, $one, $fields, $conditions, false, $operations);
        }

        return $this;
    }

    /**
     * @param string|list<string> $subject
     * @param list<string>|null $fields
     * @param array<string, mixed>|null $conditions
     * @param list<string> $operations
     */
    public function cannot(string $action, string|array $subject = 'all', ?array $fields = null, ?array $conditions = null, array $operations = Sift::ALLOWED_OPERATIONS): self
    {
        foreach ((array) $subject as $one) {
            $this->rules[] = new Rule($action, $one, $fields, $conditions, true, $operations);
        }

        return $this;
    }

    public function build(): Ability
    {
        return new Ability($this->rules);
    }
}
