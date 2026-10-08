<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Extension;

/**
 * The object `shadowCRUD(uid).field(name)` returns in shadow-crud-manager.ts (PHP-port addition:
 * upstream's object literal).
 */
final class ShadowCrudField
{
    public function __construct(private readonly ShadowCrudManager $manager, private readonly string $uid, private readonly string $fieldName)
    {
    }

    /** @return array{enabled: bool, input: bool, output: bool, filters: bool} */
    private function &config(): array
    {
        $config = &$this->manager->config($this->uid);
        if (!array_key_exists($this->fieldName, $config['fields'])) {
            $config['fields'][$this->fieldName] = ShadowCrudManager::getDefaultFieldConfig();
        }

        return $config['fields'][$this->fieldName];
    }

    public function isEnabled(): bool
    {
        return $this->config()['enabled'];
    }

    public function hasInputEnabled(): bool
    {
        return $this->config()['input'];
    }

    public function hasOutputEnabled(): bool
    {
        return $this->config()['output'];
    }

    public function hasFiltersEnabeld(): bool
    {
        return $this->config()['filters'];
    }

    public function disable(): self
    {
        $config = &$this->config();
        $config = [
            'enabled' => false,

            'output' => false,
            'input' => false,

            'filters' => false,
        ];

        return $this;
    }

    public function disableOutput(): self
    {
        $config = &$this->config();
        $config['output'] = false;

        return $this;
    }

    public function disableInput(): self
    {
        $config = &$this->config();
        $config['input'] = false;

        return $this;
    }

    public function disableFilters(): self
    {
        $config = &$this->config();
        $config['filters'] = false;

        return $this;
    }
}
