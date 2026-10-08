<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders;

use Strapi\Core\Strapi;
use Strapi\Core\Utils\LoadFiles;
use Strapi\Database\Utils\SchemaFactory;

/** Port of packages/core/core/src/loaders/components.ts: `src/components/<category>/<name>.json`. */
final class Components
{
    public function __invoke(Strapi $strapi): void
    {
        self::loadComponents($strapi);
    }

    public static function loadComponents(Strapi $strapi): void
    {
        $dir = $strapi->dirs()->components;
        if (!is_dir($dir)) {
            return;
        }

        $map = LoadFiles::loadFiles($dir, '*/*.*(php|json)');

        $components = [];
        foreach ($map as $category => $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $key => $schema) {
                if (!is_array($schema)) {
                    continue;
                }
                if (empty($schema['collectionName'])) {
                    $filePath = $dir . '/' . $category . '/' . ($schema['__filename__'] ?? "{$key}.json");

                    throw new \RuntimeException("Component {$key} is missing a \"collectionName\" property.\nVerify file {$filePath}.");
                }

                $filename = $schema['__filename__'] ?? "{$key}.json";
                unset($schema['__filename__']);
                $uid = "{$category}.{$key}";
                // upstream: Object.assign(schema, { __schema__: cloneDeep(schema), ... }) and the
                // `__filename__` loadFiles() defines; kept in config for the content-type-builder
                $schema['config'] = [
                    ...(is_array($schema['config'] ?? null) ? $schema['config'] : []),
                    '__schema__' => $schema,
                    '__filename__' => $filename,
                ];
                $components[$uid] = SchemaFactory::component($schema, $uid);
            }
        }

        $strapi->get('components')->add($components);
    }
}
