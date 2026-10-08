<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services\SchemaBuilder;

use Strapi\ContentTypeBuilder\Services\Constants;
use Strapi\ContentTypeBuilder\Utils\Attributes;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of server/src/services/schema-builder/content-type-builder.ts: the content-type methods
 * upstream spreads into the schema builder object, as a trait of {@see SchemaBuilder}.
 *
 * Attributes are plain arrays: where upstream mutates the attribute objects it was given
 * (`attribute.dominant = true`, `_.defaults(newAttribute, ...)`), the local attribute map is
 * updated so later reads see the change, as they would see the shared object upstream.

 */
trait ContentTypeBuilder
{
    /**
     * @param array{key: string, uid: string, attribute: array<string, mixed>} $options
     */
    public function setRelation(array $options): void
    {
        ['key' => $key, 'uid' => $uid, 'attribute' => $attribute] = $options;

        if (!array_key_exists('target', $attribute)) {
            return;
        }

        $target = $attribute['target'];
        $targetCT = is_string($target) ? ($this->contentTypes[$target] ?? null) : null;

        if ($targetCT === null) {
            $targetName = is_scalar($target) ? (string) $target : 'undefined';

            throw new ApplicationError("Content type {$targetName} not found");
        }

        $targetAttributeName = $attribute['targetAttribute'] ?? null;
        $targetAttribute = $targetCT->getAttribute(is_scalar($targetAttributeName) ? (string) $targetAttributeName : null);

        if (!Attributes::truthy($targetAttributeName)) {
            return;
        }

        // When generating the inverse relation, preserve existing conditions if they exist
        $targetAttributeData = is_array($targetAttribute) ? $targetAttribute : [];

        $targetCT->setAttribute(
            (string) $targetAttributeName,
            self::generateRelation($key, $attribute, $uid, $targetAttributeData),
        );
    }

    /** @param array<string, mixed> $attribute */
    public function unsetRelation(array $attribute): ?SchemaHandler
    {
        if (!Attributes::truthy($attribute['target'] ?? null)) {
            return null;
        }

        $targetCT = $this->contentTypes[(string) $attribute['target']] ?? null;
        if ($targetCT === null) {
            return null;
        }

        $targetAttributeName = Attributes::truthy($attribute['inversedBy'] ?? null) ? $attribute['inversedBy'] : ($attribute['mappedBy'] ?? null);
        $targetAttribute = $targetCT->getAttribute(is_scalar($targetAttributeName) ? (string) $targetAttributeName : null);

        if (!Attributes::truthy($targetAttribute)) {
            return null;
        }

        return $targetCT->deleteAttribute((string) $targetAttributeName);
    }

    /** @param array<string, mixed> $attributes */
    public function createContentTypeAttributes(string $uid, array $attributes): SchemaHandler
    {
        if (!isset($this->contentTypes[$uid])) {
            throw new ApplicationError('contentType.notFound');
        }

        $contentType = $this->contentTypes[$uid];

        // support self referencing content type relation
        foreach ($attributes as $key => $attribute) {
            if (is_array($attribute) && ($attribute['target'] ?? null) === '__self__') {
                $attributes[$key]['target'] = $uid;
            }
        }

        $contentType->setAttributes($this->convertAttributes($attributes));

        foreach (array_keys($attributes) as $key) {
            $key = (string) $key;
            $attribute = $attributes[$key];

            if (is_array($attribute) && Attributes::isRelation($attribute)) {
                $this->assignDominance($attributes, $key, $uid);

                $this->setRelation([
                    'key' => $key,
                    'uid' => $uid,
                    'attribute' => $attributes[$key],
                ]);
            }
        }

        return $contentType;
    }

    /**
     * Creates a content type in memory to be written to files later on.
     *
     * @param array<string, mixed> $infos
     */
    public function createContentType(array $infos): SchemaHandler
    {
        // TODO:: check for unique uid / singularName & pluralName & collectionName

        $givenUid = $infos['uid'] ?? null;
        if (Attributes::truthy($givenUid) && $givenUid !== self::createContentTypeUID($infos)) {
            throw new ApplicationError('contentType.invalidUID');
        }

        $uid = is_string($givenUid) ? $givenUid : self::createContentTypeUID($infos);

        if (isset($this->contentTypes[$uid])) {
            throw new ApplicationError('contentType.alreadyExists');
        }

        $singularName = (string) ($infos['singularName'] ?? '');
        $plugin = $infos['plugin'] ?? null;
        $dirs = $this->strapi->dirs();
        $dir = Attributes::truthy($plugin)
            ? SchemaHandler::join($dirs->extensions, (string) $plugin, 'content-types', $singularName)
            : SchemaHandler::join($dirs->api, $singularName, 'content-types', $singularName);

        $contentType = SchemaHandler::createSchemaHandler([
            'modelName' => $infos['singularName'] ?? null,
            'dir' => $dir,
            'filename' => 'schema.json',
        ]);

        $this->contentTypes[$uid] = $contentType;

        $info = [];
        foreach (['singularName', 'pluralName', 'displayName', 'description'] as $key) {
            if (array_key_exists($key, $infos)) {
                $info[$key] = $infos[$key];
            }
        }

        $contentType
            ->setUID($uid)
            ->set('kind', Attributes::truthy($infos['kind'] ?? null) ? $infos['kind'] : Constants::TYPE_KINDS['COLLECTION_TYPE'])
            ->set(
                'collectionName',
                Attributes::truthy($infos['collectionName'] ?? null) ? $infos['collectionName'] : Strings::nameToCollectionName((string) ($infos['pluralName'] ?? '')),
            )
            ->set('info', $info)
            ->set('options', self::mergeDraftAndPublish($infos))
            ->set('pluginOptions', $infos['pluginOptions'] ?? null)
            ->set('config', $infos['config'] ?? null);

        $attributes = $infos['attributes'] ?? [];
        $this->createContentTypeAttributes($uid, is_array($attributes) ? $attributes : []);

        return $contentType;
    }

    /** @param array<string, mixed> $infos */
    public function editContentType(array $infos): SchemaHandler
    {
        $uid = (string) ($infos['uid'] ?? '');

        if (!isset($this->contentTypes[$uid])) {
            throw new ApplicationError('contentType.notFound');
        }

        $contentType = $this->contentTypes[$uid];

        $oldAttributes = $contentType->schema()['attributes'] ?? [];

        /** @var array<string, array<string, mixed>> $newAttributes */
        $newAttributes = array_filter(
            is_array($infos['attributes'] ?? null) ? $infos['attributes'] : [],
            static fn (mixed $attr, string|int $key): bool => !(array_key_exists($key, $oldAttributes) && !Attributes::isConfigurable(is_array($oldAttributes[$key]) ? $oldAttributes[$key] : [])),
            ARRAY_FILTER_USE_BOTH,
        );

        $oldKeys = array_map('strval', array_keys($oldAttributes));
        $newKeysAll = array_map('strval', array_keys($newAttributes));
        $newKeys = array_values(array_diff($newKeysAll, $oldKeys));
        $deletedKeys = array_values(array_diff($oldKeys, $newKeysAll));
        $remainingKeys = array_values(array_intersect($oldKeys, $newKeysAll));

        // remove old relations
        foreach ($deletedKeys as $key) {
            $attribute = $oldAttributes[$key];

            // if the old relation has a target attribute. we need to remove it in the target type
            if (Attributes::isConfigurable($attribute) && Attributes::isRelation($attribute)) {
                $targetAttributeName = Attributes::truthy($attribute['inversedBy'] ?? null) ? $attribute['inversedBy'] : ($attribute['mappedBy'] ?? null);

                if ($targetAttributeName !== null) {
                    $this->unsetRelation($attribute);
                }
            }
        }

        foreach ($remainingKeys as $key) {
            $oldAttribute = $oldAttributes[$key];
            $newAttribute = $newAttributes[$key];

            if (!Attributes::isRelation($oldAttribute) && Attributes::isRelation($newAttribute)) {
                $this->setRelation(['key' => $key, 'uid' => $uid, 'attribute' => $newAttribute]);
                continue;
            }

            if (Attributes::isRelation($oldAttribute) && !Attributes::isRelation($newAttribute)) {
                $this->unsetRelation($oldAttribute);
                continue;
            }

            if (Attributes::isRelation($oldAttribute) && Attributes::isRelation($newAttribute)) {
                $oldTargetAttributeName = Attributes::truthy($oldAttribute['inversedBy'] ?? null) ? $oldAttribute['inversedBy'] : ($oldAttribute['mappedBy'] ?? null);

                $sameRelation = ($oldAttribute['relation'] ?? null) === ($newAttribute['relation'] ?? null);
                $targetAttributeHasChanged = $oldTargetAttributeName !== ($newAttribute['targetAttribute'] ?? null);

                if (!$sameRelation || $targetAttributeHasChanged) {
                    $this->unsetRelation($oldAttribute);
                }

                // keep extra options that were set manually on oldAttribute
                $newAttribute = self::reuseUnsetPreviousProperties($newAttribute, $oldAttribute);

                // Handle conditions explicitly - only preserve if present and not undefined in new attribute
                $hasNewConditions = isset($newAttributes[$key]['conditions']);

                if (Attributes::truthy($oldAttribute['conditions'] ?? null)) {
                    if ($hasNewConditions) {
                        // Conditions are still present, keep them
                        $newAttribute['conditions'] = $newAttributes[$key]['conditions'];
                    } else {
                        // Conditions were removed (undefined or null), ensure they're not preserved
                        unset($newAttribute['conditions']);
                    }
                } elseif ($hasNewConditions) {
                    // New conditions added
                    $newAttribute['conditions'] = $newAttributes[$key]['conditions'];
                }

                if (Attributes::truthy($oldAttribute['inversedBy'] ?? null)) {
                    $newAttribute['dominant'] = true;
                } elseif (Attributes::truthy($oldAttribute['mappedBy'] ?? null)) {
                    $newAttribute['dominant'] = false;
                }

                $newAttributes[$key] = $newAttribute;

                $this->setRelation(['key' => $key, 'uid' => $uid, 'attribute' => $newAttribute]);
            }
        }

        // add new relations
        foreach ($newKeys as $key) {
            $attribute = $newAttributes[$key];

            if (Attributes::isRelation($attribute)) {
                $this->assignDominance($newAttributes, $key, $uid);

                $this->setRelation(['key' => $key, 'uid' => $uid, 'attribute' => $newAttributes[$key]]);
            }
        }

        $contentType
            ->set('kind', Attributes::truthy($infos['kind'] ?? null) ? $infos['kind'] : ($contentType->schema()['kind'] ?? null))
            ->set(['info', 'displayName'], $infos['displayName'] ?? null)
            ->set(['info', 'description'], $infos['description'] ?? null)
            ->set('options', self::mergeDraftAndPublish($infos))
            ->set('pluginOptions', $infos['pluginOptions'] ?? null)
            ->setAttributes($this->convertAttributes($newAttributes));

        return $contentType;
    }

    public function deleteContentType(string $uid): SchemaHandler
    {
        if (!isset($this->contentTypes[$uid])) {
            throw new ApplicationError('contentType.notFound');
        }

        foreach ($this->components as $compo) {
            $compo->removeContentType($uid);
        }

        foreach ($this->contentTypes as $ct) {
            $ct->removeContentType($uid);
        }

        return $this->contentTypes[$uid]->delete();
    }

    /**
     * The `dominant` flag of a new oneToOne / manyToMany relation (written back into `$attributes`,
     * where upstream mutates the attribute object).
     *
     * @param array<array-key, mixed> $attributes
     */
    private function assignDominance(array &$attributes, string $key, string $uid): void
    {
        $relationAttribute = $attributes[$key];
        if (!is_array($relationAttribute) || !in_array($relationAttribute['relation'] ?? null, ['manyToMany', 'oneToOne'], true)) {
            return;
        }

        if (($relationAttribute['target'] ?? null) === $uid && array_key_exists('targetAttribute', $relationAttribute)) {
            // self referencing relation
            $targetAttributeName = $relationAttribute['targetAttribute'];
            $targetAttribute = is_scalar($targetAttributeName) ? ($attributes[(string) $targetAttributeName] ?? null) : null;

            if (!is_array($targetAttribute)) {
                // upstream reads `.dominant` of undefined here
                throw new \TypeError("Cannot read properties of undefined (reading 'dominant')");
            }

            $attributes[$key]['dominant'] = !array_key_exists('dominant', $targetAttribute);
        } else {
            $attributes[$key]['dominant'] = true;
        }
    }

    /**
     * `{ ...infos.options, draftAndPublish: infos.draftAndPublish }`.
     *
     * @param array<string, mixed> $infos
     * @return array<string, mixed>
     */
    private static function mergeDraftAndPublish(array $infos): array
    {
        $options = is_array($infos['options'] ?? null) ? $infos['options'] : [];
        if (array_key_exists('draftAndPublish', $infos)) {
            $options['draftAndPublish'] = $infos['draftAndPublish'];
        } else {
            unset($options['draftAndPublish']);
        }

        return $options;
    }

    /**
     * `_.defaults(newAttribute, _.omit(oldAttribute, [...]))`.
     *
     * @param array<string, mixed> $newAttribute
     * @param array<string, mixed> $oldAttribute
     * @return array<string, mixed>
     */
    private static function reuseUnsetPreviousProperties(array $newAttribute, array $oldAttribute): array
    {
        $omitted = ['configurable', 'required', 'private', 'unique', 'pluginOptions', 'inversedBy', 'mappedBy', 'conditions'];

        foreach ($oldAttribute as $key => $value) {
            if (in_array($key, $omitted, true)) {
                continue;
            }
            // _.defaults only fills properties that are undefined
            if (!array_key_exists($key, $newAttribute)) {
                $newAttribute[$key] = $value;
            }
        }

        return $newAttribute;
    }

    /**
     * Returns a uid from a content type infos.
     *
     * @param array<string, mixed> $infos
     */
    public static function createContentTypeUID(array $infos): string
    {
        $plugin = $infos['plugin'] ?? null;
        $singularName = (string) ($infos['singularName'] ?? '');

        return Attributes::truthy($plugin)
            ? "plugin::{$plugin}.{$singularName}"
            : "api::{$singularName}.{$singularName}";
    }

    /**
     * @param array<string, mixed> $attribute
     * @param array<string, mixed> $targetAttribute
     * @return array<string, mixed>
     */
    private static function generateRelation(string $key, array $attribute, string $uid, array $targetAttribute = []): array
    {
        $opts = [];

        switch ($attribute['relation'] ?? null) {
            case 'oneToOne':
                $opts['relation'] = 'oneToOne';
                $opts[Attributes::truthy($attribute['dominant'] ?? null) ? 'mappedBy' : 'inversedBy'] = $key;
                break;
            case 'oneToMany':
                $opts['relation'] = 'manyToOne';
                $opts['inversedBy'] = $key;
                break;
            case 'manyToOne':
                $opts['relation'] = 'oneToMany';
                $opts['mappedBy'] = $key;
                break;
            case 'manyToMany':
                $opts['relation'] = 'manyToMany';
                $opts[Attributes::truthy($attribute['dominant'] ?? null) ? 'mappedBy' : 'inversedBy'] = $key;
                break;
            default:
        }

        // we do this just to make sure we have the same key order when writing to files
        $result = ['type' => 'relation'];
        if (isset($opts['relation'])) {
            $result['relation'] = $opts['relation'];
        }
        $result['target'] = $uid;
        foreach (['required', 'private', 'pluginOptions'] as $prop) {
            if (Attributes::truthy($targetAttribute[$prop] ?? null)) {
                $result[$prop] = $targetAttribute[$prop];
            }
        }
        // Preserve conditions from targetAttribute if they exist
        if (Attributes::truthy($targetAttribute['conditions'] ?? null)) {
            $result['conditions'] = $targetAttribute['conditions'];
        }
        foreach (['mappedBy', 'inversedBy'] as $prop) {
            if (isset($opts[$prop])) {
                $result[$prop] = $opts[$prop];
            }
        }

        return $result;
    }
}
