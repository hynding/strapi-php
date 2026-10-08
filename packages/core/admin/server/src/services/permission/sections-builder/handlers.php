<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission\SectionsBuilder;

use Strapi\Core\Core;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/**
 * Port of server/src/services/permission/sections-builder/handlers.ts.
 *
 * Every handler receives `['action' => $action, 'section' => &$section]` (see {@see Section}) and
 * mutates the referenced section. `strapi.contentTypes` / `strapi.components` are read from the
 * current Strapi instance, as upstream reads the global `strapi`.
 */
final class Handlers
{
    /** @return array<string, Schema> */
    private static function contentTypes(): array
    {
        return Core::instance()?->contentTypes() ?? [];
    }

    /** @return array<string, Schema> */
    private static function components(): array
    {
        return Core::instance()?->components() ?? [];
    }

    /** @param Schema|array<string, mixed>|null $ct */
    private static function isHiddenFromContentManager(Schema|array|null $ct): bool
    {
        if ($ct === null) {
            return false;
        }
        $pluginOptions = $ct instanceof Schema ? $ct->pluginOptions : ($ct['pluginOptions'] ?? []);

        return ($pluginOptions['content-manager']['visible'] ?? null) === false;
    }

    /**
     * Transforms & adds the given setting action to the section.
     * Note: The action is transformed to a setting specific format.
     *
     * @param array{action: array<string, mixed>, section: list<array<string, mixed>>} $ctx
     */
    public static function settings(array $ctx): void
    {
        $action = $ctx['action'];

        $ctx['section'][] = [
            'displayName' => $action['displayName'] ?? null,
            'category' => $action['category'] ?? null,
            'subCategory' => $action['subCategory'] ?? null,
            // TODO: Investigate at which point the action property is transformed to actionId
            'action' => $action['actionId'] ?? null,
        ];
    }

    /**
     * Transforms & adds the given plugin action to the section.
     * Note: The action is transformed to a plugin specific format.
     *
     * @param array{action: array<string, mixed>, section: list<array<string, mixed>>} $ctx
     */
    public static function plugins(array $ctx): void
    {
        $action = $ctx['action'];

        $ctx['section'][] = [
            'displayName' => $action['displayName'] ?? null,
            'plugin' => $action['pluginName'] ?? null,
            'subCategory' => $action['subCategory'] ?? null,
            'action' => $action['actionId'] ?? null,
        ];
    }

    /**
     * Transforms & adds the given action to the section's actions field.
     * Note: The action is transformed to a content-type specific format.
     *
     * @param array{action: array<string, mixed>, section: array{actions: list<array<string, mixed>>, subjects: list<array<string, mixed>>}} $ctx
     */
    public static function contentTypesBase(array $ctx): void
    {
        $action = $ctx['action'];
        $subjects = $action['subjects'] ?? null;

        $item = [
            'label' => $action['displayName'] ?? null,
            'actionId' => $action['actionId'] ?? null,
        ];

        // Strip non-displayed content types so they don't appear as configurable subjects
        // in the roles UI (e.g. plugin::users-permissions.role).
        if (is_array($subjects)) {
            $allContentTypes = self::contentTypes();
            $item['subjects'] = array_values(array_filter(
                $subjects,
                static fn (mixed $uid): bool => !self::isHiddenFromContentManager($allContentTypes[$uid] ?? null),
            ));
        }

        $options = $action['options'] ?? null;

        $ctx['section']['actions'][] = [...$item, ...Utils::getValidOptions(is_array($options) ? $options : null)];
    }

    /**
     * Initialize the subjects array of a section based on the action's subjects.
     *
     * @return \Closure(array{action: array<string, mixed>, section: array{actions: list<array<string, mixed>>, subjects: list<array<string, mixed>>}}): void
     */
    public static function subjectsHandlerFor(string $kind): \Closure
    {
        return static function (array $ctx) use ($kind): void {
            $subjects = $ctx['action']['subjects'] ?? null;

            if (!is_array($subjects) || $subjects === []) {
                return;
            }

            $isNotInSubjects = Utils::isNotInSubjects($ctx['section']['subjects']);
            $isOfKind = Utils::isOfKind($kind);

            foreach ($subjects as $uid) {
                // Ignore already added subjects
                if (!$isNotInSubjects($uid)) {
                    continue;
                }
                // Transform UIDs into content-types
                $ct = Utils::resolveContentType((string) $uid);
                // Only keep specific kind of content-types
                if ($ct === null || !$isOfKind($ct)) {
                    continue;
                }
                // Exclude content types hidden from the content manager (e.g. plugin::users-permissions.role).
                // Those types are valid explorer.read subjects so that super admin can read relation targets,
                // but they should not appear as configurable permissions in the roles UI.
                if (self::isHiddenFromContentManager($ct)) {
                    continue;
                }
                // Transform the content-types into section's subjects
                $ctx['section']['subjects'][] = Utils::toSubjectTemplate($ct);
            }
        };
    }

    /**
     * @param Schema|array<string, mixed> $model
     * @param array<string, mixed> $attribute
     * @return array<string, mixed>|null
     */
    private static function buildNode(Schema|array $model, string $attributeName, array $attribute): ?array
    {
        if (!ContentTypes::isVisibleAttribute($model, $attributeName)) {
            return null;
        }

        $node = ['label' => $attributeName, 'value' => $attributeName];

        if (($attribute['required'] ?? false) === true) {
            $node['required'] = true;
        }

        if (($attribute['type'] ?? null) === 'component') {
            $component = self::components()[$attribute['component'] ?? ''] ?? null;

            return [...$node, 'children' => $component === null ? [] : self::buildDeepAttributesCollection($component)];
        }

        return $node;
    }

    /**
     * @param Schema|array<string, mixed> $model
     * @return list<array<string, mixed>>
     */
    private static function buildDeepAttributesCollection(Schema|array $model): array
    {
        $out = [];
        foreach (ContentTypes::attributes($model) as $attributeName => $attribute) {
            $node = self::buildNode($model, (string) $attributeName, $attribute);
            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * Create and populate the fields property for section's subjects based on the action's subjects list.
     *
     * @param array{action: array<string, mixed>, section: array{actions: list<array<string, mixed>>, subjects: list<array<string, mixed>>}} $ctx
     */
    public static function fieldsProperty(array $ctx): void
    {
        $subjects = $ctx['action']['subjects'] ?? null;
        $subjects = is_array($subjects) ? $subjects : [];

        foreach ($ctx['section']['subjects'] as $index => $subject) {
            if (!in_array($subject['uid'] ?? null, $subjects, true)) {
                continue;
            }

            if (Utils::hasProperty('fields', $subject)) {
                continue;
            }

            $contentType = Utils::resolveContentType((string) $subject['uid']);

            $fields = $contentType === null ? [] : self::buildDeepAttributesCollection($contentType);
            $fieldsProp = ['label' => 'Fields', 'value' => 'fields', 'children' => $fields];

            $ctx['section']['subjects'][$index]['properties'][] = $fieldsProp;
        }
    }
}
