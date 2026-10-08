<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Utils;

use Strapi\Generators\Plop;
use Strapi\Generators\Template;

/**
 * Not an upstream file: the "register the new file in the plugin's index files" actions that
 * upstream's api, content-type, controller, middleware, policy and service generators repeat
 * inline — create the missing `<type>/index.php` (and for routes `routes/index.php`,
 * `routes/content-api/index.php`, `routes/admin/index.php`), then append the entry to it.
 */
final class PluginIndexActions
{
    /**
     * @param list<'controllers'|'services'|'routes'|'policies'|'middlewares'|'content-types'> $types
     * @param array<string, mixed> $answers
     * @param 'core'|'custom' $router how the generated route file is spread into `routes/content-api/index.php`
     * @return list<array<string, mixed>>
     */
    public static function actions(Plop $plop, string $filePath, array $answers, array $types, string $singularName, string $router = 'custom'): array
    {
        $actions = [];
        $exists = static fn (string $path): bool => file_exists($plop->getDestBasePath() . '/' . Template::render($path, $answers));

        foreach ($types as $type) {
            if ($type !== 'routes') {
                if (!$exists("{$filePath}/{$type}/index.php")) {
                    // Create index file if it doesn't exist
                    $actions[] = [
                        'type' => 'add',
                        'path' => "{$filePath}/{$type}/index.php",
                        'templateFile' => 'templates/php/plugin/plugin.index.php.tpl',
                        'skipIfExists' => true,
                    ];
                }

                // Append the new entry to the index.php file
                $actions[] = [
                    'type' => 'modify',
                    'path' => "{$filePath}/{$type}/index.php",
                    'transform' => static fn (string $template): string => ExtendPluginIndexFiles::appendToFile($template, [
                        'type' => $type === 'content-types' ? 'content-type' : 'index',
                        'singularName' => $singularName,
                    ]),
                ];

                continue;
            }

            if (!$exists("{$filePath}/routes/index.php")) {
                $actions[] = [
                    'type' => 'add',
                    'path' => "{$filePath}/routes/index.php",
                    'templateFile' => 'templates/php/plugin/plugin.routes.index.php.tpl',
                    'skipIfExists' => true,
                ];
            }

            foreach (['content-api', 'admin'] as $routeType) {
                if (!$exists("{$filePath}/routes/{$routeType}/index.php")) {
                    $actions[] = [
                        'type' => 'add',
                        'path' => "{$filePath}/routes/{$routeType}/index.php",
                        'templateFile' => 'templates/php/plugin/plugin.routes.type.index.php.tpl',
                        'data' => ['type' => $routeType],
                        'skipIfExists' => true,
                    ];
                }
            }

            $actions[] = [
                'type' => 'modify',
                'path' => "{$filePath}/routes/content-api/index.php",
                'transform' => static fn (string $template): string => ExtendPluginIndexFiles::appendToFile($template, [
                    'type' => 'routes',
                    'singularName' => $singularName,
                    'router' => $router,
                ]),
            ];
        }

        return $actions;
    }
}
