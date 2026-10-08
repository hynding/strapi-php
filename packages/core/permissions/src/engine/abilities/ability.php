<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Abilities;

use Strapi\Utils\Qs;

/**
 * The subset of `@casl/ability`'s `Ability` Strapi uses, plus the `@casl/ability/extra` helpers.
 *
 *  - `can(action, subject = 'all', field = null)` / `cannot(...)`: `action` is a string or a
 *    parametrized action `['name' => ..., 'params' => [...]]` (serialized as `name?qs`), `subject` a
 *    uid string, `'all'`, or an entity tagged with {@see Subject::subject()} whose conditions are then
 *    evaluated in memory with {@see Sift}.
 *  - `rules`, `rulesFor(action, subjectType, field)`, `relevantRuleFor(...)`, `possibleRulesFor(...)`.
 *  - `permittedFieldsOf(action, subject, fieldsFrom)` and `rulesToQuery(action, subjectType, convert)`.
 *
 * Rule precedence follows CASL: later rules win, `inverted` rules deny, a rule without conditions
 * matching the subject type grants regardless of entity. Checking a bare subject type against rules
 * that all carry conditions is allowed (CASL: "can read article" is true when some rule could apply).
 */
final class Ability
{
    /** @var list<Rule> */
    private array $rules;

    /** @param list<Rule> $rules */
    public function __construct(array $rules = [])
    {
        $this->rules = array_values($rules);
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * `ability.rules` as plain arrays (`{ action, subject, fields?, conditions?, inverted }`).
     *
     * @return list<array{action: string, subject: string, fields?: list<string>, conditions?: array<string, mixed>, inverted: bool}>
     */
    public function rulesAsArrays(): array
    {
        return array_map(static fn (Rule $rule): array => $rule->toArray(), $this->rules);
    }

    /**
     * @param string|array{name: string, params: array<string, mixed>} $action
     */
    public static function normalizeAction(string|array $action): string
    {
        if (is_string($action)) {
            return $action;
        }

        return self::buildParametrizedAction($action);
    }

    /** @param array{name: string, params: array<string, mixed>} $parametrizedAction */
    public static function buildParametrizedAction(array $parametrizedAction): string
    {
        return $parametrizedAction['name'] . '?' . Qs::stringify($parametrizedAction['params']);
    }

    /**
     * @param string|array{name: string, params: array<string, mixed>} $action
     */
    public function can(string|array $action, mixed $subject = 'all', ?string $field = null): bool
    {
        $rule = $this->relevantRuleFor($action, $subject, $field);

        return $rule !== null && !$rule->inverted;
    }

    /**
     * @param string|array{name: string, params: array<string, mixed>} $action
     */
    public function cannot(string|array $action, mixed $subject = 'all', ?string $field = null): bool
    {
        return !$this->can($action, $subject, $field);
    }

    /**
     * The last rule (highest precedence) that applies to the action, subject (type and, for an
     * entity, its conditions) and field.
     *
     * @param string|array{name: string, params: array<string, mixed>} $action
     */
    public function relevantRuleFor(string|array $action, mixed $subject = 'all', ?string $field = null): ?Rule
    {
        $subjectType = Subject::detectSubjectType($subject) ?? 'all';
        $entity = is_string($subject) ? null : Subject::entity($subject);

        foreach ($this->rulesFor($action, $subjectType, $field) as $rule) {
            if (self::ruleMatchesSubject($rule, $entity)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Every rule whose action and subject type match, regardless of conditions (CASL `possibleRulesFor`).
     *
     * @param string|array{name: string, params: array<string, mixed>} $action
     * @return list<Rule>
     */
    public function possibleRulesFor(string|array $action, mixed $subjectType = 'all'): array
    {
        $action = self::normalizeAction($action);
        $type = is_string($subjectType) ? $subjectType : (Subject::detectSubjectType($subjectType) ?? 'all');

        // CASL indexes rules so that the last registered (highest priority) comes first
        return array_values(array_filter(
            array_reverse($this->rules),
            static fn (Rule $rule): bool => self::actionMatches($rule->action, $action) && self::subjectMatches($rule->subject, $type),
        ));
    }

    /**
     * `possibleRulesFor` further filtered by field (CASL `rulesFor`).
     *
     * @param string|array{name: string, params: array<string, mixed>} $action
     * @return list<Rule>
     */
    public function rulesFor(string|array $action, mixed $subjectType = 'all', ?string $field = null): array
    {
        return array_values(array_filter($this->possibleRulesFor($action, $subjectType), static fn (Rule $rule): bool => $rule->matchesField($field)));
    }

    /**
     * CASL `rule.matchesConditions(subject)`: unconditional rules always match; against a bare subject
     * type (no entity) a conditional rule matches unless it is inverted; otherwise sift decides.
     */
    private static function ruleMatchesSubject(Rule $rule, mixed $entity): bool
    {
        if ($rule->conditions === null || $rule->conditions === []) {
            return true;
        }
        if ($entity === null) {
            return !$rule->inverted;
        }

        return $rule->matchesConditions($entity);
    }

    private static function actionMatches(string $ruleAction, string $action): bool
    {
        return $ruleAction === $action || $ruleAction === 'manage';
    }

    private static function subjectMatches(string $ruleSubject, string $subjectType): bool
    {
        return $ruleSubject === $subjectType || $ruleSubject === 'all';
    }

    /**
     * `permittedFieldsOf(ability, action, subject, { fieldsFrom })` from `@casl/ability/extra`:
     * walks rules in precedence order, adding fields of allowing rules and removing fields of
     * inverted rules, for the rules whose conditions match the subject.
     *
     * @param string|array{name: string, params: array<string, mixed>} $action
     * @param callable(Rule): list<string>|null $fieldsFrom  defaults to `rule.fields ?? []`
     * @return list<string>
     */
    public function permittedFieldsOf(string|array $action, mixed $subject = 'all', ?callable $fieldsFrom = null): array
    {
        $fieldsFrom ??= static fn (Rule $rule): array => $rule->fields ?? [];
        $subjectType = Subject::detectSubjectType($subject) ?? 'all';
        $entity = is_string($subject) ? null : Subject::entity($subject);

        $fields = [];
        // CASL walks possibleRulesFor() from the lowest priority rule (first registered) to the highest,
        // adding fields of allowing rules and deleting fields of inverted ones
        foreach (array_reverse($this->possibleRulesFor($action, $subjectType)) as $rule) {
            if (!self::ruleMatchesSubject($rule, $entity)) {
                continue;
            }
            $ruleFields = $fieldsFrom($rule);
            if ($rule->inverted) {
                $fields = array_values(array_diff($fields, $ruleFields));
            } else {
                $fields = array_values(array_unique([...$fields, ...$ruleFields]));
            }
        }

        return $fields;
    }

    /**
     * `rulesToQuery(ability, action, subjectType, convert)` from `@casl/ability/extra`: builds
     * `{ $or: [...convert(allowing rule)], $and: [...convert(inverted rule)] }` walking rules from the
     * highest priority down, `null` when nothing is allowed, and an empty query `[]` when an
     * unconditional rule allows everything. As in CASL, negating inverted rules is `convert`'s job.
     *
     * @param string|array{name: string, params: array<string, mixed>} $action
     * @param callable(Rule): mixed|null $convert  defaults to `rule.conditions`
     * @return array<string, mixed>|null
     */
    public function rulesToQuery(string|array $action, mixed $subjectType = 'all', ?callable $convert = null): ?array
    {
        $convert ??= static fn (Rule $rule): mixed => $rule->conditions;
        $and = [];
        $or = [];

        foreach ($this->rulesFor($action, $subjectType) as $rule) {
            if ($rule->conditions === null || $rule->conditions === []) {
                if ($rule->inverted) {
                    // stop if inverted rule without fields and conditions
                    break;
                }

                // if it allows reading all types then remove previous conditions
                return $and !== [] ? ['$and' => $and] : [];
            }

            if ($rule->inverted) {
                $and[] = $convert($rule);
            } else {
                $or[] = $convert($rule);
            }
        }

        // no regular conditions and no unconditional rule: not allowed on this subject type
        if ($or === []) {
            return null;
        }

        return $and !== [] ? ['$or' => $or, '$and' => $and] : ['$or' => $or];
    }
}
