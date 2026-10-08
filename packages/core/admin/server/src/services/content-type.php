<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Admin\Domain\Action\Action as ActionDomain;
use Strapi\Admin\Domain\Permission\Permission as PermissionDomain;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/**
 * Port of server/src/services/content-type.ts.
 *
 * @phpstan-type FieldOptions array{prefix?: string, nestingLevel?: int|null, requiredOnly?: bool, existingFields?: list<string>, restrictedSubjects?: list<string>, components?: array<string, Schema|array<string, mixed>>}
 */
final class ContentType
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Creates an array of paths to the fields and nested fields, without path nodes.
     *
     * @param Schema|array<string, mixed>|null $model
     * @param FieldOptions $options
     * @return list<string>
     */
    public function getNestedFields(Schema|array|null $model, array $options = []): array
    {
        return self::nestedFields($model, $options);
    }

    /**
     * @param Schema|array<string, mixed>|null $model
     * @param FieldOptions $options
     * @return list<string>
     */
    private static function nestedFields(Schema|array|null $model, array $options): array
    {
        $prefix = $options['prefix'] ?? '';
        $nestingLevel = $options['nestingLevel'] ?? 15;
        $components = $options['components'] ?? [];
        $requiredOnly = $options['requiredOnly'] ?? false;
        $existingFields = $options['existingFields'] ?? [];

        if ($nestingLevel === 0) {
            return $prefix !== '' ? [$prefix] : [];
        }

        $nonAuthorizableFields = $model === null ? [] : ContentTypes::getNonVisibleAttributes($model);

        $fields = [];
        foreach (ContentTypes::attributes($model) as $key => $attr) {
            $key = (string) $key;
            if (in_array($key, $nonAuthorizableFields, true)) {
                continue;
            }

            $fieldPath = $prefix !== '' ? "{$prefix}.{$key}" : $key;
            $shouldBeIncluded = !$requiredOnly || ($attr['required'] ?? null) === true;
            $insideExistingFields = false;
            foreach ($existingFields as $existingField) {
                if (str_starts_with((string) $existingField, $fieldPath)) {
                    $insideExistingFields = true;
                    break;
                }
            }

            if (($attr['type'] ?? null) === 'component') {
                if ($shouldBeIncluded || $insideExistingFields) {
                    $compoFields = self::nestedFields($components[$attr['component'] ?? ''] ?? null, [
                        'nestingLevel' => $nestingLevel - 1,
                        'prefix' => $fieldPath,
                        'components' => $components,
                        'requiredOnly' => $requiredOnly,
                        'existingFields' => $existingFields,
                    ]);

                    if ($compoFields === [] && $shouldBeIncluded) {
                        $fields[] = $fieldPath;
                        continue;
                    }

                    array_push($fields, ...$compoFields);
                }
                continue;
            }

            if ($shouldBeIncluded) {
                $fields[] = $fieldPath;
            }
        }

        return $fields;
    }

    /**
     * Creates an array of paths to the fields and nested fields, with path nodes.
     *
     * @param Schema|array<string, mixed>|null $model
     * @param FieldOptions $options
     * @return list<string>
     */
    public function getNestedFieldsWithIntermediate(Schema|array|null $model, array $options = []): array
    {
        return self::nestedFieldsWithIntermediate($model, $options);
    }

    /**
     * @param Schema|array<string, mixed>|null $model
     * @param FieldOptions $options
     * @return list<string>
     */
    private static function nestedFieldsWithIntermediate(Schema|array|null $model, array $options): array
    {
        $prefix = $options['prefix'] ?? '';
        $nestingLevel = $options['nestingLevel'] ?? 15;
        $components = $options['components'] ?? [];

        if ($nestingLevel === 0) {
            return [];
        }

        $nonAuthorizableFields = $model === null ? [] : ContentTypes::getNonVisibleAttributes($model);

        $fields = [];
        foreach (ContentTypes::attributes($model) as $key => $attr) {
            $key = (string) $key;
            if (in_array($key, $nonAuthorizableFields, true)) {
                continue;
            }

            $fieldPath = $prefix !== '' ? "{$prefix}.{$key}" : $key;
            $fields[] = $fieldPath;

            if (($attr['type'] ?? null) === 'component') {
                $compoFields = self::nestedFieldsWithIntermediate($components[$attr['component'] ?? ''] ?? null, [
                    'nestingLevel' => $nestingLevel - 1,
                    'prefix' => $fieldPath,
                    'components' => $components,
                ]);

                array_push($fields, ...$compoFields);
            }
        }

        return $fields;
    }

    /**
     * Creates an array of permissions with the "properties.fields" attribute filled.
     *
     * @param list<array<string, mixed>> $actions
     * @param FieldOptions $options
     * @return list<array<string, mixed>>
     */
    public function getPermissionsWithNestedFields(array $actions, array $options = []): array
    {
        $nestingLevel = $options['nestingLevel'] ?? null;
        $restrictedSubjects = $options['restrictedSubjects'] ?? [];
        $contentTypes = $this->strapi->contentTypes();
        $components = $this->strapi->components();

        $permissions = [];
        foreach ($actions as $action) {
            $subjects = is_array($action['subjects'] ?? null) ? $action['subjects'] : [];
            $validSubjects = array_filter($subjects, static fn (mixed $subject): bool => !in_array($subject, $restrictedSubjects, true));

            // Create a Permission for each subject (content-type uid) within the action
            foreach ($validSubjects as $subject) {
                $properties = [];
                if (ActionDomain::appliesToProperty('fields', $action)) {
                    $fieldOptions = ['components' => $components];
                    if ($nestingLevel !== null) {
                        $fieldOptions['nestingLevel'] = $nestingLevel;
                    }
                    $properties['fields'] = self::nestedFields($contentTypes[$subject] ?? null, $fieldOptions);
                }

                $permissions[] = PermissionDomain::create([
                    'action' => $action['actionId'],
                    'subject' => $subject,
                    'properties' => $properties,
                ]);
            }
        }

        return $permissions;
    }

    /**
     * Cleans permissions' fields (add required ones, remove the non-existing ones).
     *
     * @param list<array<string, mixed>> $permissions
     * @return list<array<string, mixed>>
     */
    public function cleanPermissionFields(array $permissions): array
    {
        /** @var Permission $permissionService */
        $permissionService = $this->strapi->service('admin::permission');
        $actionProvider = $permissionService->actionProvider;
        $contentTypes = $this->strapi->contentTypes();
        $components = $this->strapi->components();
        /** @var array<string, list<string>> $nestedFieldsCache */
        $nestedFieldsCache = [];

        return array_values(array_map(function (array $permission) use ($actionProvider, $contentTypes, $components, &$nestedFieldsCache): array {
            $actionId = (string) ($permission['action'] ?? '');
            $subject = $permission['subject'] ?? null;
            $fields = $permission['properties']['fields'] ?? null;

            $action = $actionProvider->get($actionId);

            // todo see if it's possible to check property on action + subject (async)
            if (!ActionDomain::appliesToProperty('fields', $action)) {
                return PermissionDomain::deleteProperty('fields', $permission);
            }

            if (!is_string($subject) || $subject === '' || !isset($contentTypes[$subject])) {
                return $permission;
            }

            $possibleFields = $nestedFieldsCache[$subject] ?? null;
            if ($possibleFields === null) {
                $possibleFields = self::nestedFieldsWithIntermediate($contentTypes[$subject], ['components' => $components]);
                $nestedFieldsCache[$subject] = $possibleFields;
            }

            $currentFields = is_array($fields) ? $fields : [];

            $validUserFields = [];
            foreach ($possibleFields as $pf) {
                foreach ($currentFields as $cf) {
                    if ($pf === $cf || str_starts_with($pf, "{$cf}.")) {
                        $validUserFields[] = $pf;
                        break;
                    }
                }
            }
            $validUserFields = array_values(array_unique($validUserFields));

            // A field is considered "not nested" if no other valid user field starts with this field's path followed by a dot.
            // This helps to remove redundant parent paths when a more specific child path is already included.
            // For example, if 'component.title' is present, 'component' would be filtered out by this condition.
            $isNotNestedField = static function (string $field) use ($validUserFields): bool {
                foreach ($validUserFields as $validUserField) {
                    if ($validUserField !== $field && str_starts_with($validUserField, "{$field}.")) {
                        return false;
                    }
                }

                return true;
            };

            // Filter out fields that are parent paths of other included fields.
            $newFields = array_values(array_filter($validUserFields, $isNotNestedField));

            return PermissionDomain::setProperty('fields', $newFields, $permission);
        }, $permissions));
    }
}
