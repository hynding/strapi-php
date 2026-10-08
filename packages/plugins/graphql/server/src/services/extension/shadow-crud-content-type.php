<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Extension;

/**
 * The object `shadowCRUD(uid)` returns in shadow-crud-manager.ts (PHP-port addition: upstream's
 * object literal).
 */
final class ShadowCrudContentType
{
    public function __construct(private readonly ShadowCrudManager $manager, private readonly string $uid)
    {
    }

    public function isEnabled(): bool
    {
        return $this->manager->config($this->uid)['enabled'];
    }

    public function isDisabled(): bool
    {
        return !$this->isEnabled();
    }

    public function areQueriesEnabled(): bool
    {
        return $this->manager->config($this->uid)['queries'];
    }

    public function areQueriesDisabled(): bool
    {
        return !$this->areQueriesEnabled();
    }

    public function areMutationsEnabled(): bool
    {
        return $this->manager->config($this->uid)['mutations'];
    }

    public function areMutationsDisabled(): bool
    {
        return !$this->areMutationsEnabled();
    }

    public function isActionEnabled(string $action): bool
    {
        $matchingActions = [$action, ShadowCrudManager::ALL_ACTIONS];

        foreach ($this->manager->config($this->uid)['disabledActions'] as $disabledAction) {
            if (in_array($disabledAction, $matchingActions, true)) {
                return false;
            }
        }

        return true;
    }

    public function isActionDisabled(string $action): bool
    {
        return !$this->isActionEnabled($action);
    }

    public function disable(): self
    {
        $config = &$this->manager->config($this->uid);
        $config['enabled'] = false;

        return $this;
    }

    public function disableQueries(): self
    {
        $config = &$this->manager->config($this->uid);
        $config['queries'] = false;

        return $this;
    }

    public function disableMutations(): self
    {
        $config = &$this->manager->config($this->uid);
        $config['mutations'] = false;

        return $this;
    }

    public function disableAction(string $action): self
    {
        $config = &$this->manager->config($this->uid);

        if (!in_array($action, $config['disabledActions'], true)) {
            $config['disabledActions'][] = $action;
        }

        return $this;
    }

    /** @param list<string> $actions */
    public function disableActions(array $actions = []): self
    {
        foreach ($actions as $action) {
            $this->disableAction($action);
        }

        return $this;
    }

    public function field(string $fieldName): ShadowCrudField
    {
        $config = &$this->manager->config($this->uid);

        if (!array_key_exists($fieldName, $config['fields'])) {
            $config['fields'][$fieldName] = ShadowCrudManager::getDefaultFieldConfig();
        }

        return new ShadowCrudField($this->manager, $this->uid, $fieldName);
    }
}
