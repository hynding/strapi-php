<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Utils;

use Strapi\Core\Strapi;

/** Port of utils/populate.ts (`getDeepPopulate`): a populate object based on the schema. */
final class Populate
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @param array{relationalFields?: list<string>} $opts
     * @return array<string, mixed>
     */
    public static function getDeepPopulate(Strapi $strapi, string $uid, array $opts = []): array
    {
        $cacheKey = spl_object_id($strapi) . '::' . $uid . '::' . json_encode($opts);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $model = $strapi->getModel($uid);
        if ($model === null) {
            return [];
        }

        $result = [];
        foreach ($model->attributes as $attributeName => $attribute) {
            switch ($attribute['type'] ?? null) {
                case 'relation':
                    if (!empty($attribute['unstable_virtual'])) {
                        // skip relations not managed by the DB layer
                        break;
                    }
                    // Include all relations except the ones not managed by the DB layer.
                    $result[$attributeName] = isset($opts['relationalFields']) ? ['select' => $opts['relationalFields']] : true;
                    break;

                case 'media':
                    // We populate all media fields for completeness of webhook responses
                    $result[$attributeName] = ['select' => ['*']];
                    break;

                case 'component':
                    $result[$attributeName] = ['populate' => self::getDeepPopulate($strapi, (string) $attribute['component'], $opts)];
                    break;

                case 'dynamiczone':
                    // Use fragments to populate the dynamic zone components
                    $populatedComponents = [];
                    foreach ($attribute['components'] ?? [] as $componentUID) {
                        $populatedComponents[$componentUID] = ['populate' => self::getDeepPopulate($strapi, (string) $componentUID, $opts)];
                    }
                    $result[$attributeName] = ['on' => $populatedComponents];
                    break;

                default:
                    break;
            }
        }

        self::$cache[$cacheKey] = $result;

        return $result;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
