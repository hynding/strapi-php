<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission\SectionsBuilder;

/**
 * Port of server/src/services/permission/sections-builder/builder.ts (`createSectionBuilder()`):
 * a section builder with its own sections registry.
 *
 * @phpstan-import-type SectionOptions from Section
 */
final class Builder
{
    /** @var array<string, Section> */
    private array $sections = [];

    public static function createSectionBuilder(): self
    {
        return new self();
    }

    /**
     * Create & add a section to the builder's registry.
     *
     * @param SectionOptions $options
     * @return $this
     */
    public function createSection(string $sectionName, array $options): static
    {
        $this->sections[$sectionName] = Section::createSection($options);

        return $this;
    }

    /**
     * Removes a section from the builder's registry using its unique name.
     *
     * @return $this
     */
    public function deleteSection(string $sectionName): static
    {
        unset($this->sections[$sectionName]);

        return $this;
    }

    /**
     * Register a handler function for a given section.
     *
     * @return $this
     */
    public function addHandler(string $sectionName, callable $handler): static
    {
        if (isset($this->sections[$sectionName])) {
            $this->sections[$sectionName]->hooks['handlers']->register($handler);
        }

        return $this;
    }

    /**
     * Register a matcher function for a given section.
     *
     * @return $this
     */
    public function addMatcher(string $sectionName, callable $matcher): static
    {
        if (isset($this->sections[$sectionName])) {
            $this->sections[$sectionName]->hooks['matchers']->register($matcher);
        }

        return $this;
    }

    /**
     * Build a section tree based on the registered actions and the given actions.
     *
     * @param list<array<string, mixed>> $actions
     * @return array<string, mixed>
     */
    public function build(array $actions = []): array
    {
        $sections = [];

        foreach ($this->sections as $sectionName => $section) {
            $sections[$sectionName] = $section->build($actions);
        }

        return $sections;
    }
}
