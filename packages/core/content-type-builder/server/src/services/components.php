<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services;

use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaBuilder;
use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaHandler;
use Strapi\ContentTypeBuilder\Utils\Attributes;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/components.ts. */
final class Components
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Formats a component attributes.
     *
     * @return array<string, mixed>
     */
    public static function formatComponent(Schema $component): array
    {
        $info = $component->info;

        $schema = [];
        if (array_key_exists('displayName', $info)) {
            $schema['displayName'] = $info['displayName'];
        }
        $schema['description'] = array_key_exists('description', $info) ? $info['description'] : '';
        if (array_key_exists('icon', $info)) {
            $schema['icon'] = $info['icon'];
        }
        $raw = $component->config['__schema__'] ?? [];
        if (is_array($raw) && array_key_exists('connection', $raw)) {
            $schema['connection'] = $raw['connection'];
        }
        $schema['collectionName'] = $component->collectionName;
        if (ContentTypes::hasPluginOptions($component)) {
            $schema['pluginOptions'] = $component->pluginOptions;
        }
        $schema['attributes'] = Attributes::formatAttributes($component);

        return [
            'uid' => $component->uid,
            'category' => $component->category,
            'apiId' => $component->modelName,
            'schema' => $schema,
        ];
    }

    /**
     * Creates a component and handle the nested components sent with it.
     *
     * @param array<string, mixed> $input `{ component, components? }`
     */
    public function createComponent(array $input): SchemaHandler
    {
        /** @var array<string, mixed> $component */
        $component = is_array($input['component'] ?? null) ? $input['component'] : [];
        /** @var list<array<string, mixed>> $components */
        $components = is_array($input['components'] ?? null) ? $input['components'] : [];

        $builder = SchemaBuilder::createBuilder($this->strapi);

        $uidMap = $builder->createNewComponentUIDMap($components);
        $replaceTmpUIDs = Attributes::replaceTemporaryUIDs($uidMap, $this->strapi);

        $newComponent = $builder->createComponent($replaceTmpUIDs($component));

        foreach ($components as $nested) {
            if (!array_key_exists('uid', $nested)) {
                $builder->createComponent($replaceTmpUIDs($nested));
            } else {
                $builder->editComponent($replaceTmpUIDs($nested));
            }
        }

        $builder->writeFiles();

        $this->strapi->eventHub()->emit('component.create', ['component' => $newComponent]);

        return $newComponent;
    }

    /** @param array<string, mixed> $input `{ component, components? }` */
    public function editComponent(string $uid, array $input): SchemaHandler
    {
        /** @var array<string, mixed> $component */
        $component = is_array($input['component'] ?? null) ? $input['component'] : [];
        /** @var list<array<string, mixed>> $components */
        $components = is_array($input['components'] ?? null) ? $input['components'] : [];

        $builder = SchemaBuilder::createBuilder($this->strapi);

        $uidMap = $builder->createNewComponentUIDMap($components);
        $replaceTmpUIDs = Attributes::replaceTemporaryUIDs($uidMap, $this->strapi);

        $updatedComponent = $builder->editComponent(['uid' => $uid, ...$replaceTmpUIDs($component)]);

        foreach ($components as $nested) {
            if (!array_key_exists('uid', $nested)) {
                $builder->createComponent($replaceTmpUIDs($nested));
            } else {
                $builder->editComponent($replaceTmpUIDs($nested));
            }
        }

        $builder->writeFiles();

        $this->strapi->eventHub()->emit('component.update', ['component' => $updatedComponent]);

        return $updatedComponent;
    }

    public function deleteComponent(string $uid): SchemaHandler
    {
        $builder = SchemaBuilder::createBuilder($this->strapi);

        $deletedComponent = $builder->deleteComponent($uid);

        $builder->writeFiles();

        $this->strapi->eventHub()->emit('component.delete', ['component' => $deletedComponent]);

        return $deletedComponent;
    }
}
