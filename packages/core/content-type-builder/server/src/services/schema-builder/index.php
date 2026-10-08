<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services\SchemaBuilder;

use Strapi\Core\Core;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/schema-builder/index.ts: `createBuilder()` returns a schema builder
 * holding a {@see SchemaHandler} for every registered content type and component, with the
 * component and content-type builder methods mixed in ({@see ComponentBuilder},
 * {@see ContentTypeBuilder}).
 *
 * The `components` / `contentTypes` maps (JS `Map`s) are ordered arrays keyed by uid.
 */
final class SchemaBuilder
{
    use ComponentBuilder;
    use ContentTypeBuilder;

    /** @var array<string, SchemaHandler> */
    public array $components = [];

    /** @var array<string, SchemaHandler> */
    public array $contentTypes = [];

    /**
     * @param list<array<string, mixed>> $components
     * @param list<array<string, mixed>> $contentTypes
     */
    public function __construct(public readonly Strapi $strapi, array $components = [], array $contentTypes = [])
    {
        // init temporary ContentTypes
        foreach ($contentTypes as $infos) {
            /** @phpstan-ignore argument.type */
            $this->contentTypes[(string) $infos['uid']] = SchemaHandler::createSchemaHandler($infos);
        }

        // init temporary components
        foreach ($components as $infos) {
            /** @phpstan-ignore argument.type */
            $this->components[(string) $infos['uid']] = SchemaHandler::createSchemaHandler($infos);
        }
    }

    /** Creates a content type schema builder instance from the registered content types and components. */
    public static function createBuilder(?Strapi $strapi = null): self
    {
        $strapi ??= Core::instance() ?? throw new \RuntimeException('Strapi is not initialized');
        $dirs = $strapi->dirs();

        $components = [];
        foreach ($strapi->components() as $componentInput) {
            $config = $componentInput->config;
            $components[] = [
                'category' => $componentInput->category,
                'modelName' => $componentInput->modelName,
                'plugin' => $componentInput->modelName,
                'uid' => $componentInput->uid,
                'filename' => is_string($config['__filename__'] ?? null) ? $config['__filename__'] : "{$componentInput->modelName}.json",
                'dir' => SchemaHandler::join($dirs->components, (string) $componentInput->category),
                'schema' => is_array($config['__schema__'] ?? null) ? $config['__schema__'] : self::schemaFromModel($componentInput->toArray()),
                'config' => $config,
            ];
        }

        $contentTypes = [];
        foreach ($strapi->contentTypes() as $contentTypeInput) {
            $singularName = (string) ($contentTypeInput->info['singularName'] ?? $contentTypeInput->modelName);
            $dir = $contentTypeInput->plugin !== null
                ? SchemaHandler::join($dirs->extensions, $contentTypeInput->plugin, 'content-types', $singularName)
                : SchemaHandler::join($dirs->api, (string) $contentTypeInput->apiName, 'content-types', $singularName);
            $config = $contentTypeInput->config;

            $contentTypes[] = [
                'modelName' => $contentTypeInput->modelName,
                'plugin' => $contentTypeInput->plugin,
                'uid' => $contentTypeInput->uid,
                'filename' => 'schema.json',
                'dir' => $dir,
                'schema' => is_array($config['__schema__'] ?? null) ? $config['__schema__'] : self::schemaFromModel($contentTypeInput->toArray()),
                'config' => $config,
            ];
        }

        return new self($strapi, $components, $contentTypes);
    }

    /**
     * Schemas registered without their raw definition (built in by the database package):
     * the definition keys of the loaded model.
     *
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private static function schemaFromModel(array $model): array
    {
        return array_intersect_key($model, array_flip(['kind', 'collectionName', 'info', 'options', 'pluginOptions', 'attributes']));
    }

    /**
     * Convert Attributes received from the API to the right syntax.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function convertAttributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $attribute) {
            $out[$key] = $this->convertAttribute(is_array($attribute) ? $attribute : []);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $attribute
     * @return array<string, mixed>
     */
    public function convertAttribute(array $attribute): array
    {
        $applyBaseProperties = static function (array $attr) use ($attribute): array {
            if (($attribute['private'] ?? null) === true) {
                $attr['private'] = true;
            } else {
                unset($attr['private']);
            }

            if (($attribute['configurable'] ?? null) === false) {
                $attr['configurable'] = false;
            } else {
                unset($attr['configurable']);
            }

            // IMPORTANT: Preserve conditions only if they exist and are not undefined/null
            if (isset($attribute['conditions'])) {
                $attr['conditions'] = $attribute['conditions'];
            }

            return $attr;
        };

        if (($attribute['type'] ?? null) === 'relation') {
            $attr = ['type' => 'relation'];
            if (array_key_exists('relation', $attribute)) {
                $attr['relation'] = $attribute['relation'];
            }
            if (array_key_exists('target', $attribute)) {
                $attr['target'] = $attribute['target'];
            }
            foreach ($attribute as $key => $value) {
                if (!in_array($key, ['target', 'relation', 'targetAttribute', 'dominant'], true)) {
                    $attr[$key] = $value;
                }
            }
            $attr = $applyBaseProperties($attr);

            $relation = $attribute['relation'] ?? null;
            $targetAttribute = $attribute['targetAttribute'] ?? null;
            $dominant = $attribute['dominant'] ?? null;

            if ($targetAttribute === null) {
                return $attr;
            }

            if (in_array($relation, ['oneToOne', 'manyToMany'], true) && $dominant === true) {
                $attr['inversedBy'] = $targetAttribute;
            } elseif (in_array($relation, ['oneToOne', 'manyToMany'], true) && $dominant === false) {
                $attr['mappedBy'] = $targetAttribute;
            } elseif (in_array($relation, ['oneToOne', 'manyToOne', 'manyToMany'], true)) {
                $attr['inversedBy'] = $targetAttribute;
            } elseif ($relation === 'oneToMany') {
                $attr['mappedBy'] = $targetAttribute;
            }

            return $attr;
        }

        return $applyBaseProperties($attribute);
    }

    /**
     * Write all types to files.
     *
     * @return bool true if all files have been written correctly, false if an error occurred (and was rolled back)
     * @throws ApplicationError if an error occurred and the rollback failed
     */
    public function writeFiles(): bool
    {
        try {
            foreach ([...array_values($this->components), ...array_values($this->contentTypes)] as $schema) {
                $schema->flush();
            }

            return true;
        } catch (\Throwable $error) {
            $this->strapi->log()->error('Error writing schema files');
            $this->strapi->log()->error($error->getMessage());

            try {
                $this->rollback();

                return false;
            } catch (\Throwable $rollbackError) {
                $this->strapi->log()->error('Error rolling back schema files. You might need to fix your files manually');
                $this->strapi->log()->error($rollbackError->getMessage());

                throw new ApplicationError('Invalid schema edition');
            }
        }
    }

    /** Rollback all files. */
    public function rollback(): void
    {
        foreach ([...array_values($this->components), ...array_values($this->contentTypes)] as $schema) {
            $schema->rollback();
        }
    }
}
