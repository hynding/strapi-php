<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Abilities;

/**
 * The `{ can, buildParametrizedAction, build }` object `caslAbilityBuilder()` returns.
 *
 * @phpstan-import-type PermissionRule from CaslAbility
 * @phpstan-import-type ParametrizedAction from CaslAbility
 */
class CustomAbilityBuilder
{
    private readonly AbilityBuilder $builder;

    public function __construct()
    {
        $this->builder = new AbilityBuilder();
    }

    /** @param PermissionRule $permission */
    public function can(array $permission): static
    {
        $action = $permission['action'];
        $subject = $permission['subject'] ?? null;
        $fields = $permission['properties']['fields'] ?? null;
        $condition = $permission['condition'] ?? null;

        $caslAction = is_string($action) ? $action : Ability::buildParametrizedAction($action);

        $this->builder->can(
            $caslAction,
            $subject === null ? 'all' : $subject,
            $fields,
            is_array($condition) && !array_is_list($condition) ? $condition : null,
            CaslAbility::ALLOWED_OPERATIONS,
        );

        return $this;
    }

    /** @param ParametrizedAction $parametrizedAction */
    public function buildParametrizedAction(array $parametrizedAction): string
    {
        return Ability::buildParametrizedAction($parametrizedAction);
    }

    public function build(): Ability
    {
        return $this->builder->build();
    }
}
