<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Abilities;

/**
 * One ability rule, the shape CASL's `ability.rules` / `rulesFor()` expose:
 * `{ action, subject, fields?, conditions?, inverted }`.
 *
 * `conditions` keeps the raw Mongo-style query (what `rulesToQuery` reads) and `matchesConditions()`
 * evaluates it in memory with {@see Sift}.
 */
final class Rule
{
    /** @var \Closure(mixed): bool|null */
    private ?\Closure $tester = null;

    /**
     * @param list<string>|null $fields
     * @param array<string, mixed>|null $conditions
     * @param list<string> $operations  operators allowed in conditions
     */
    public function __construct(
        public readonly string $action,
        public readonly string $subject,
        public readonly ?array $fields = null,
        public readonly ?array $conditions = null,
        public readonly bool $inverted = false,
        private readonly array $operations = Sift::ALLOWED_OPERATIONS,
    ) {
        if ($fields !== null && $fields === []) {
            // CASL: `rawRule.fields` cannot be an empty array. https://bit.ly/390miLa
            throw new \InvalidArgumentException('`rawRule.fields` cannot be an empty array. https://bit.ly/390miLa');
        }
    }

    public function matchesConditions(mixed $object): bool
    {
        if ($this->conditions === null || $this->conditions === []) {
            return true;
        }

        $this->tester ??= self::conditionsMatcher($this->conditions, $this->operations);

        return ($this->tester)($object);
    }

    /**
     * Match an RBAC condition query against an entity in memory with sift; unsupported operators
     * raise the same actionable error as upstream's `conditionsMatcher`.
     *
     * @param array<string, mixed> $conditions
     * @param list<string> $operations
     * @return \Closure(mixed): bool
     */
    public static function conditionsMatcher(array $conditions, array $operations = Sift::ALLOWED_OPERATIONS): \Closure
    {
        try {
            return Sift::createQueryTester($conditions, $operations);
        } catch (\InvalidArgumentException $error) {
            if (preg_match('/^Unsupported operation: (.+)$/', $error->getMessage(), $m) === 1) {
                throw new \RuntimeException(sprintf(
                    'RBAC condition uses unsupported operator "%s". Conditions are matched in memory and support only: %s.',
                    $m[1],
                    implode(', ', $operations),
                ), 0, $error);
            }

            throw $error;
        }
    }

    /**
     * CASL field matching: `*` matches one segment, `**` matches any depth (`title.nested`).
     */
    public function matchesField(?string $field): bool
    {
        if ($this->fields === null) {
            return true;
        }

        if ($field === null || $field === '') {
            // checking access to at least one field: inverted rules only disallow, keep looking for a regular rule
            return !$this->inverted;
        }

        foreach ($this->fields as $pattern) {
            if (self::fieldMatches($pattern, $field)) {
                return true;
            }
        }

        return false;
    }

    public static function fieldMatches(string $pattern, string $field): bool
    {
        if ($pattern === $field) {
            return true;
        }
        if (!str_contains($pattern, '*')) {
            return false;
        }

        // CASL's wildcard rules: `**` → any characters (dots included), `*` → any characters but dots
        $regex = '/^' . str_replace(['\*\*', '\*'], ['.*', '[^.]*'], preg_quote($pattern, '/')) . '$/';
        $regex = str_replace('.*[^.]*', '.*', $regex);

        return preg_match($regex, $field) === 1;
    }

    /** @return array{action: string, subject: string, fields?: list<string>, conditions?: array<string, mixed>, inverted: bool} */
    public function toArray(): array
    {
        $out = ['action' => $this->action, 'subject' => $this->subject];
        if ($this->fields !== null) {
            $out['fields'] = $this->fields;
        }
        if ($this->conditions !== null) {
            $out['conditions'] = $this->conditions;
        }
        $out['inverted'] = $this->inverted;

        return $out;
    }
}
