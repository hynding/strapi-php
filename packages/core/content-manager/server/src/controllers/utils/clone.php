<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers\Utils;

use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of server/src/controllers/utils/clone.ts. The class is `CloneUtils`: `clone` is a reserved
 * word in PHP.
 *
 * Use an array of strings to represent the path to a field, so we can show breadcrumbs in the admin
 * We can't use special characters as delimiters, because the path includes display names
 * for dynamic zone components, which can contain any character.
 *
 * @phpstan-type ProhibitedCloningField array{0: list<string>, 1: 'unique'|'relation'}
 */
final class CloneUtils
{
    /**
     * @param list<string> $path
     * @return list<ProhibitedCloningField>
     */
    private static function checkRelation(Schema $model, string $attributeName, array $path): array
    {
        // we don't care about createdBy, updatedBy, localizations etc.
        if (!ContentTypes::isVisibleAttribute($model, $attributeName)) {
            // Return empty array and not null so we can always spread the result
            return [];
        }

        /**
         * Only one-to-many and one-to-one (when they're reversed, not one-way) are dangerous,
         * because the other relations don't "steal" the relation from the entry we're cloning
         */
        $attribute = $model->attributes[$attributeName];
        $relation = $attribute['relation'] ?? null;

        if (
            in_array($relation, ['oneToOne', 'oneToMany'], true)
            && (($attribute['mappedBy'] ?? null) !== null || ($attribute['inversedBy'] ?? null) !== null)
        ) {
            return [[[...$path, $attributeName], 'relation']];
        }

        return [];
    }

    /**
     * @param list<string> $pathPrefix
     * @return list<ProhibitedCloningField>
     */
    public static function getProhibitedCloningFields(Strapi $strapi, string $uid, array $pathPrefix = []): array
    {
        $model = $strapi->getModel($uid);
        if ($model === null) {
            throw new \RuntimeException("Model {$uid} not found");
        }

        $acc = [];
        foreach ($model->attributes as $attributeName => $attribute) {
            $attributeName = (string) $attributeName;
            $attributePath = [...$pathPrefix, $attributeName];

            switch ($attribute['type'] ?? null) {
                case 'relation':
                    $acc = [...$acc, ...self::checkRelation($model, $attributeName, $pathPrefix)];
                    break;
                case 'component':
                    $acc = [...$acc, ...self::getProhibitedCloningFields($strapi, (string) $attribute['component'], $attributePath)];
                    break;
                case 'dynamiczone':
                    foreach (is_array($attribute['components'] ?? null) ? $attribute['components'] : [] as $componentUID) {
                        $componentModel = $strapi->getModel((string) $componentUID);
                        $acc = [
                            ...$acc,
                            ...self::getProhibitedCloningFields($strapi, (string) $componentUID, [
                                ...$attributePath,
                                (string) ($componentModel->info['displayName'] ?? ''),
                            ]),
                        ];
                    }
                    break;
                case 'uid':
                    $acc[] = [$attributePath, 'unique'];
                    break;
                default:
                    if (!empty($attribute['unique'])) {
                        $acc[] = [$attributePath, 'unique'];
                    }
                    break;
            }
        }

        return $acc;
    }

    /**
     * Iterates all attributes of the content type, and removes the ones that are not creatable.
     *   - If it's a relation, it sets the value to [] or null.
     *   - If it's a regular attribute, it sets the value to null.
     * When cloning, if you don't set a field it will be copied from the original entry. So we need to
     * remove the fields that the user can't create.
     *
     * @param PermissionChecker $permissionChecker
     * @return \Closure(array<string, mixed>, list<string>=): array<string, mixed>
     */
    public static function excludeNotCreatableFields(Strapi $strapi, string $uid, object $permissionChecker): \Closure
    {
        return static function (array $body, array $path = []) use ($strapi, $uid, $permissionChecker): array {
            $model = $strapi->getModel($uid);
            if ($model === null) {
                return $body;
            }
            $canCreate = static fn (string $path): bool => $permissionChecker->can('create', null, $path);

            foreach ($model->attributes as $attributeName => $attribute) {
                $attributeName = (string) $attributeName;
                $attributePath = implode('.', [...$path, $attributeName]);

                // Ignore the attribute if it's not visible
                if (!ContentTypes::isVisibleAttribute($model, $attributeName)) {
                    continue;
                }

                switch ($attribute['type'] ?? null) {
                    // Relation should be empty if the user can't create it
                    case 'relation':
                        if ($canCreate($attributePath)) {
                            break;
                        }
                        $body = Objects::set($body, $attributePath, ['set' => []]);
                        break;
                    // Go deeper into the component
                    case 'component':
                        $body = self::excludeNotCreatableFields($strapi, (string) $attribute['component'], $permissionChecker)($body, [
                            ...array_values($path),
                            $attributeName,
                        ]);
                        break;
                    // Attribute should be null if the user can't create it
                    default:
                        if ($canCreate($attributePath)) {
                            break;
                        }
                        $body = Objects::set($body, $attributePath, null);
                        break;
                }
            }

            return $body;
        };
    }
}
