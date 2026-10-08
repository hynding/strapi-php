<?php

declare(strict_types=1);

namespace Strapi\Admin\Domain\Action;

/**
 * Port of server/src/domain/action/index.ts: the domain representation of an admin action.
 *
 * An action is a plain array:
 * `{ actionId, section, displayName, category, subCategory?, pluginName?, subjects?, options: { applyToProperties }, aliases? }`.
 *
 * @phpstan-type ActionArray array<string, mixed>
 */
final class Action
{
    /** Get the list of all the valid attributes of an action. */
    public const array ACTION_FIELDS = [
        'section',
        'displayName',
        'category',
        'subCategory',
        'pluginName',
        'subjects',
        'options',
        'actionId',
        'aliases',
    ];

    /**
     * Upstream exports `actionFields`.
     *
     * @return list<string>
     */
    public static function actionFields(): array
    {
        return self::ACTION_FIELDS;
    }

    /**
     * Return the default attributes of a new action.
     *
     * @return array{options: array{applyToProperties: null}}
     */
    public static function getDefaultActionAttributes(): array
    {
        return ['options' => ['applyToProperties' => null]];
    }

    /**
     * Remove unwanted attributes from an action (`pick(actionFields)`).
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public static function sanitizeActionAttributes(array $action): array
    {
        $out = [];
        foreach (self::ACTION_FIELDS as $field) {
            if (array_key_exists($field, $action)) {
                $out[$field] = $action[$field];
            }
        }

        return $out;
    }

    /**
     * Create and return an identifier for a create-action payload, based on its source
     * (`pluginName` or 'application') and `uid`.
     *
     * @param array<string, mixed> $attributes
     */
    public static function computeActionId(array $attributes): string
    {
        $pluginName = $attributes['pluginName'] ?? null;
        $uid = (string) ($attributes['uid'] ?? '');

        if ($pluginName === null || $pluginName === '' || $pluginName === false) {
            return "api::{$uid}";
        }

        if ($pluginName === 'admin') {
            return "admin::{$uid}";
        }

        return "plugin::{$pluginName}.{$uid}";
    }

    /**
     * Assign an actionId attribute to a create-action payload.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public static function assignActionId(array $attrs): array
    {
        $attrs['actionId'] = self::computeActionId($attrs);

        return $attrs;
    }

    /**
     * Add or remove the subCategory attribute (only settings & plugins actions have one).
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public static function assignOrOmitSubCategory(array $action): array
    {
        $shouldHaveSubCategory = in_array($action['section'] ?? null, ['settings', 'plugins'], true);

        if ($shouldHaveSubCategory) {
            $subCategory = $action['subCategory'] ?? null;
            $action['subCategory'] = ($subCategory === null || $subCategory === '') ? 'general' : $subCategory;

            return $action;
        }

        unset($action['subCategory']);

        return $action;
    }

    /**
     * Check if a property can be applied to an action.
     *
     * @param array<string, mixed>|null $action
     */
    public static function appliesToProperty(string $property, ?array $action): bool
    {
        $applyToProperties = $action['options']['applyToProperties'] ?? null;

        return is_array($applyToProperties) && in_array($property, $applyToProperties, true);
    }

    /**
     * Check if an action applies to a subject.
     *
     * @param array<string, mixed>|null $action
     */
    public static function appliesToSubject(?string $subject, ?array $action): bool
    {
        $subjects = $action['subjects'] ?? null;

        return is_array($subjects) && in_array($subject, $subjects, true);
    }

    /**
     * Transform the given attributes into a domain representation of an action.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function create(array $payload): array
    {
        $action = self::sanitizeActionAttributes(self::assignOrOmitSubCategory(self::assignActionId($payload)));

        // merge(getDefaultActionAttributes(), action)
        $options = $action['options'] ?? [];
        $action['options'] = [
            'applyToProperties' => null,
            ...(is_array($options) ? $options : []),
        ];

        return $action;
    }
}
