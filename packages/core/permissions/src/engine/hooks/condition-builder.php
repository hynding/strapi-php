<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Hooks;

/** The `condition.and(raw)` / `condition.or(raw)` helpers of the will-register context. */
final class ConditionBuilder
{
    /** @var array<string, mixed> */
    private array $permission;

    /** @param array<string, mixed> $permission */
    public function __construct(array &$permission)
    {
        $this->permission = &$permission;
    }

    public function and(mixed $rawConditionObject): self
    {
        if (!isset($this->permission['condition']) || !is_array($this->permission['condition'])) {
            $this->permission['condition'] = ['$and' => []];
        }

        if (is_array($this->permission['condition']['$and'] ?? null)) {
            $this->permission['condition']['$and'][] = $rawConditionObject;
        }

        return $this;
    }

    public function or(mixed $rawConditionObject): self
    {
        if (!isset($this->permission['condition']) || !is_array($this->permission['condition'])) {
            $this->permission['condition'] = ['$and' => []];
        }

        if (is_array($this->permission['condition']['$and'] ?? null)) {
            foreach ($this->permission['condition']['$and'] as $index => $clause) {
                if (is_array($clause) && array_key_exists('$or', $clause)) {
                    $this->permission['condition']['$and'][$index]['$or'][] = $rawConditionObject;

                    return $this;
                }
            }

            $this->permission['condition']['$and'][] = ['$or' => [$rawConditionObject]];
        }

        return $this;
    }
}
