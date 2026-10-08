<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services;

use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaBuilder;
use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaHandler;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Strings;

/** Port of server/src/services/component-categories.ts. */
final class ComponentCategories
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Edit a category name and move components to the write folder.
     *
     * @param array{name?: mixed} $infos
     */
    public function editCategory(string $name, array $infos): ?string
    {
        $newName = Strings::nameToSlug((string) ($infos['name'] ?? ''));

        // don't do anything the name doesn't change
        if ($name === $newName) {
            return null;
        }

        if (!$this->categoryExists($name)) {
            throw new ApplicationError('category not found');
        }

        if ($this->categoryExists($newName)) {
            throw new ApplicationError('Name already taken');
        }

        $builder = SchemaBuilder::createBuilder($this->strapi);

        foreach ($builder->components as $component) {
            $oldUID = (string) $component->uid();
            $newUID = "{$newName}.{$component->modelName()}";

            // only edit the components in this specific category
            if ($component->category() !== $name) {
                continue;
            }

            $component->setUID($newUID)->setDir(SchemaHandler::join($this->strapi->dirs()->components, $newName));

            foreach ($builder->components as $compo) {
                $compo->updateComponent($oldUID, $newUID);
            }

            foreach ($builder->contentTypes as $ct) {
                $ct->updateComponent($oldUID, $newUID);
            }
        }

        $builder->writeFiles();

        return $newName;
    }

    /** Deletes a category and its components. */
    public function deleteCategory(string $name): void
    {
        if (!$this->categoryExists($name)) {
            throw new ApplicationError('category not found');
        }

        $builder = SchemaBuilder::createBuilder($this->strapi);

        foreach ($builder->components as $component) {
            if ($component->category() === $name) {
                $builder->deleteComponent((string) $component->uid());
            }
        }

        $builder->writeFiles();
    }

    /** Checks if a category exists. */
    private function categoryExists(string $name): bool
    {
        foreach ($this->strapi->components() as $component) {
            if ($component->category === $name) {
                return true;
            }
        }

        return false;
    }
}
