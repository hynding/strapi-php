<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services\SchemaBuilder;

use Strapi\ContentTypeBuilder\Utils\Attributes;
use Strapi\ContentTypeBuilder\Utils\Pluralize;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of server/src/services/schema-builder/component-builder.ts: the component methods upstream
 * spreads into the schema builder object, as a trait of {@see SchemaBuilder}.

 */
trait ComponentBuilder
{
    /** @param array<string, mixed> $infos */
    public function createComponentUID(array $infos): string
    {
        return Strings::nameToSlug((string) ($infos['category'] ?? '')) . '.' . Strings::nameToSlug((string) ($infos['displayName'] ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $components
     * @return array<string, string>
     */
    public function createNewComponentUIDMap(array $components): array
    {
        $uidMap = [];
        foreach ($components as $component) {
            $tmpUID = $component['tmpUID'] ?? null;
            $uidMap[is_scalar($tmpUID) ? (string) $tmpUID : 'undefined'] = $this->createComponentUID($component);
        }

        return $uidMap;
    }

    /** @param array<string, mixed> $attributes */
    public function createComponentAttributes(string $uid, array $attributes): SchemaHandler
    {
        if (!isset($this->components[$uid])) {
            throw new ApplicationError('component.notFound');
        }

        return $this->components[$uid]->setAttributes($this->convertAttributes($attributes));
    }

    /**
     * Create a component in the tmpComponent map.
     *
     * @param array<string, mixed> $infos
     */
    public function createComponent(array $infos): SchemaHandler
    {
        $givenUid = $infos['uid'] ?? null;
        if (Attributes::truthy($givenUid) && $givenUid !== $this->createComponentUID($infos)) {
            throw new ApplicationError('component.invalidUID');
        }

        $uid = is_string($givenUid) ? $givenUid : $this->createComponentUID($infos);

        if (isset($this->components[$uid])) {
            throw new ApplicationError('component.alreadyExists');
        }

        $category = (string) ($infos['category'] ?? '');
        $displayName = (string) ($infos['displayName'] ?? '');

        $handler = SchemaHandler::createSchemaHandler([
            'dir' => SchemaHandler::join($this->strapi->dirs()->components, Strings::nameToSlug($category)),
            'filename' => Strings::nameToSlug($displayName) . '.json',
        ]);

        // TODO: create a utility for this
        // Duplicate in admin/src/components/FormModal/forms/utils/createCollectionName.ts
        $collectionName = 'components_' . Strings::nameToCollectionName($category) . '_' . Strings::nameToCollectionName(Pluralize::plural($displayName));

        foreach ($this->components as $compo) {
            if (($compo->schema()['collectionName'] ?? null) === $collectionName) {
                throw new ApplicationError('component.alreadyExists');
            }
        }

        $handler
            ->setUID($uid)
            ->set('collectionName', $collectionName)
            ->set(['info', 'displayName'], $infos['displayName'] ?? null)
            ->set(['info', 'icon'], $infos['icon'] ?? null)
            ->set(['info', 'description'], $infos['description'] ?? null)
            ->set('pluginOptions', $infos['pluginOptions'] ?? null)
            ->set('config', $infos['config'] ?? null);

        $this->strapi->telemetry()->send(count($this->components) === 0 ? 'didCreateFirstComponent' : 'didCreateComponent');

        $this->components[$uid] = $handler;

        $attributes = $infos['attributes'] ?? [];
        $this->createComponentAttributes($uid, is_array($attributes) ? $attributes : []);

        return $handler;
    }

    /**
     * Edit a component in the tmpComponent map.
     *
     * @param array<string, mixed> $infos
     */
    public function editComponent(array $infos): SchemaHandler
    {
        $uid = (string) ($infos['uid'] ?? '');

        if (!isset($this->components[$uid])) {
            throw new ApplicationError('component.notFound');
        }

        $component = $this->components[$uid];

        $nameUID = explode('.', $uid)[1] ?? '';

        $newCategory = Strings::nameToSlug((string) ($infos['category'] ?? ''));
        $newUID = "{$newCategory}.{$nameUID}";

        if ($newUID !== $uid && isset($this->components[$newUID])) {
            throw new ApplicationError('component.edit.alreadyExists');
        }

        $newDir = SchemaHandler::join($this->strapi->dirs()->components, $newCategory);

        $oldAttributes = $component->schema()['attributes'] ?? [];

        $newAttributes = array_filter(
            is_array($infos['attributes'] ?? null) ? $infos['attributes'] : [],
            static fn (mixed $attr, string|int $key): bool => !(array_key_exists($key, $oldAttributes) && !Attributes::isConfigurable(is_array($oldAttributes[$key]) ? $oldAttributes[$key] : [])),
            ARRAY_FILTER_USE_BOTH,
        );

        $component
            ->setUID($newUID)
            ->setDir($newDir)
            ->set(['info', 'displayName'], $infos['displayName'] ?? null)
            ->set(['info', 'icon'], $infos['icon'] ?? null)
            ->set(['info', 'description'], $infos['description'] ?? null)
            ->set('pluginOptions', $infos['pluginOptions'] ?? null)
            ->setAttributes($this->convertAttributes($newAttributes));

        if ($newUID !== $uid) {
            foreach ($this->components as $compo) {
                $compo->updateComponent($uid, $newUID);
            }

            foreach ($this->contentTypes as $ct) {
                $ct->updateComponent($uid, $newUID);
            }
        }

        return $component;
    }

    public function deleteComponent(string $uid): SchemaHandler
    {
        if (!isset($this->components[$uid])) {
            throw new ApplicationError('component.notFound');
        }

        foreach ($this->components as $compo) {
            $compo->removeComponent($uid);
        }

        foreach ($this->contentTypes as $ct) {
            $ct->removeComponent($uid);
        }

        return $this->components[$uid]->delete();
    }
}
