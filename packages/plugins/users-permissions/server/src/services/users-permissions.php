<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Services;

use Strapi\Types\Core\Module;
use Strapi\Core\Registries\ActionMap;
use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Utils\UrlJoin;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\RouteSerialization;
use Strapi\Utils\Template;

/** Port of server/src/services/users-permissions.js. */
final class UsersPermissions
{
    public const DEFAULT_PERMISSIONS = [
        ['action' => 'plugin::users-permissions.auth.callback', 'roleType' => 'public'],
        ['action' => 'plugin::users-permissions.auth.connect', 'roleType' => 'public'],
        ['action' => 'plugin::users-permissions.auth.forgotPassword', 'roleType' => 'public'],
        ['action' => 'plugin::users-permissions.auth.resetPassword', 'roleType' => 'public'],
        ['action' => 'plugin::users-permissions.auth.register', 'roleType' => 'public'],
        ['action' => 'plugin::users-permissions.auth.emailConfirmation', 'roleType' => 'public'],
        ['action' => 'plugin::users-permissions.auth.sendEmailConfirmation', 'roleType' => 'public'],
        ['action' => 'plugin::users-permissions.auth.refresh', 'roleType' => 'public'],
        ['action' => 'plugin::users-permissions.auth.logout', 'roleType' => 'authenticated'],
        ['action' => 'plugin::users-permissions.auth.getSessions', 'roleType' => 'authenticated'],
        ['action' => 'plugin::users-permissions.auth.revokeSession', 'roleType' => 'authenticated'],
        ['action' => 'plugin::users-permissions.user.me', 'roleType' => 'authenticated'],
        ['action' => 'plugin::users-permissions.auth.changePassword', 'roleType' => 'authenticated'],
    ];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    private static function transformRoutePrefixFor(string $pluginName, array $route): array
    {
        $prefix = is_array($route['config'] ?? null) && array_key_exists('prefix', $route['config']) ? $route['config']['prefix'] : null;
        $path = $prefix !== null ? $prefix . $route['path'] : "/{$pluginName}" . $route['path'];

        return [...$route, 'path' => $path];
    }

    /**
     * The content-API actions of a module's controllers: `{ [controllerName]: { [action]: { enabled, policy } } }`.
     * An action is a content-API one when it is bound to a content-API route (upstream's `__type__` symbol).
     *
     * @return array<string, array<string, array{enabled: bool, policy: string}>>
     */
    private function contentApiControllers(string $source, string $name, Module $module, bool $defaultEnable): array
    {
        $permissions = $this->strapi->contentAPI()->permissions;
        $controllers = [];
        foreach ($module->controllers() as $controllerName => $controller) {
            $uid = "{$source}::{$name}.{$controllerName}";
            $actions = [];
            foreach (ActionMap::actionNames($controller) as $action) {
                if ($permissions->isContentApiAction($uid, $action)) {
                    $actions[$action] = ['enabled' => $defaultEnable, 'policy' => ''];
                }
            }

            if ($actions === []) {
                continue;
            }

            $controllers[(string) $controllerName] = $actions;
        }

        return $controllers;
    }

    /**
     * @param array{defaultEnable?: bool} $options
     * @return array<string, array{controllers: array<string, array<string, array{enabled: bool, policy: string}>>}>
     */
    public function getActions(array $options = []): array
    {
        $defaultEnable = $options['defaultEnable'] ?? false;
        $actionMap = [];

        foreach ($this->strapi->apis() as $apiName => $api) {
            $controllers = $this->contentApiControllers('api', (string) $apiName, $api, $defaultEnable);

            if ($controllers !== []) {
                $actionMap["api::{$apiName}"] = ['controllers' => $controllers];
            }
        }

        foreach ($this->strapi->plugins() as $pluginName => $plugin) {
            $controllers = $this->contentApiControllers('plugin', (string) $pluginName, $plugin, $defaultEnable);

            if ($controllers !== []) {
                $actionMap["plugin::{$pluginName}"] = ['controllers' => $controllers];
            }
        }

        // Return a deeply cloned version to avoid circular references
        return $actionMap;
    }

    /**
     * `_.flatMap(module.routes, (route) => has(route, 'routes') ? route.routes : route)`, each route
     * carrying its router's type as `info.type` (upstream's server sets it on the shared route objects).
     *
     * @param array<string, mixed>|list<mixed> $routes
     * @param string $defaultType the type core gives a router without one
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $transform
     * @return list<array<string, mixed>>
     */
    private static function flattenRoutes(array $routes, string $defaultType, ?\Closure $transform = null): array
    {
        $out = [];
        $add = static function (array $route, string $type) use (&$out, $transform): void {
            $info = is_array($route['info'] ?? null) ? $route['info'] : [];
            $route['info'] = [...$info, 'type' => $info['type'] ?? $type];
            $out[] = $transform !== null ? $transform($route) : $route;
        };

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            if (array_key_exists('routes', $route)) {
                $type = is_string($route['type'] ?? null) ? $route['type'] : $defaultType;
                foreach (is_array($route['routes']) ? $route['routes'] : [] as $child) {
                    if (is_array($child)) {
                        $add($child, $type);
                    }
                }
                continue;
            }
            $add($route, $defaultType);
        }

        return $out;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function getRoutes(): array
    {
        $routesMap = [];

        foreach ($this->strapi->apis() as $apiName => $api) {
            $routes = array_values(array_filter(
                self::flattenRoutes($api->routes(), 'content-api'),
                static fn (array $route): bool => ($route['info']['type'] ?? null) === 'content-api',
            ));

            if ($routes === []) {
                continue;
            }

            $apiPrefix = (string) $this->strapi->config()->get('api.rest.prefix');
            $routesMap["api::{$apiName}"] = array_map(static fn (array $route): array => [
                ...$route,
                'path' => UrlJoin::join($apiPrefix, (string) $route['path']),
            ], $routes);
        }

        foreach ($this->strapi->plugins() as $pluginName => $plugin) {
            $pluginName = (string) $pluginName;
            $transformPrefix = static fn (array $route): array => self::transformRoutePrefixFor($pluginName, $route);

            $routes = array_values(array_filter(
                self::flattenRoutes($plugin->routes(), 'admin', $transformPrefix),
                static fn (array $route): bool => ($route['info']['type'] ?? null) === 'content-api',
            ));

            if ($routes === []) {
                continue;
            }

            $apiPrefix = (string) $this->strapi->config()->get('api.rest.prefix');
            $routesMap["plugin::{$pluginName}"] = array_map(static fn (array $route): array => [
                ...$route,
                'path' => UrlJoin::join($apiPrefix, (string) $route['path']),
            ], $routes);
        }

        return RouteSerialization::sanitizeRoutesMapForSerialization($routesMap);
    }

    public function syncPermissions(): void
    {
        $roles = $this->strapi->db()->query('plugin::users-permissions.role')->findMany();
        $dbPermissions = $this->strapi->db()->query('plugin::users-permissions.permission')->findMany();

        $permissionsFoundInDB = array_values(array_unique(array_map(static fn (array $p): mixed => $p['action'] ?? null, $dbPermissions), SORT_REGULAR));

        $allActions = [];
        foreach ($this->strapi->apis() as $apiName => $api) {
            foreach ($api->controllers() as $controllerName => $controller) {
                foreach (ActionMap::actionNames($controller) as $actionName) {
                    $allActions[] = "api::{$apiName}.{$controllerName}.{$actionName}";
                }
            }
        }
        foreach ($this->strapi->plugins() as $pluginName => $plugin) {
            foreach ($plugin->controllers() as $controllerName => $controller) {
                foreach (ActionMap::actionNames($controller) as $actionName) {
                    $allActions[] = "plugin::{$pluginName}.{$controllerName}.{$actionName}";
                }
            }
        }

        $toDelete = array_values(array_filter($permissionsFoundInDB, static fn (mixed $action): bool => !in_array($action, $allActions, true)));

        foreach ($toDelete as $action) {
            $this->strapi->db()->query('plugin::users-permissions.permission')->delete(['where' => ['action' => $action]]);
        }

        if ($permissionsFoundInDB === []) {
            // create default permissions
            foreach ($roles as $role) {
                foreach (self::DEFAULT_PERMISSIONS as ['action' => $action, 'roleType' => $roleType]) {
                    if ($roleType !== ($role['type'] ?? null)) {
                        continue;
                    }

                    $this->strapi->db()->query('plugin::users-permissions.permission')->create([
                        'data' => [
                            'action' => $action,
                            'role' => $role['id'],
                        ],
                    ]);
                }
            }
        }
    }

    public function initialize(): void
    {
        $roleCount = $this->strapi->db()->query('plugin::users-permissions.role')->count();

        if ($roleCount === 0) {
            $this->strapi->db()->query('plugin::users-permissions.role')->create([
                'data' => [
                    'name' => 'Authenticated',
                    'description' => 'Default role given to authenticated user.',
                    'type' => 'authenticated',
                ],
            ]);

            $this->strapi->db()->query('plugin::users-permissions.role')->create([
                'data' => [
                    'name' => 'Public',
                    'description' => 'Default role given to unauthenticated user.',
                    'type' => 'public',
                ],
            ]);
        }

        Utils::getService($this->strapi, 'users-permissions')->syncPermissions();
    }

    /** @param array<string, mixed> $user */
    public function updateUserRole(array $user, mixed $role): mixed
    {
        return $this->strapi->db()->query('plugin::users-permissions.user')->update(['where' => ['id' => $user['id']], 'data' => ['role' => $role]]);
    }

    /**
     * `_.template(layout, { interpolate, evaluate: false, escape: false })(data)` with a strict
     * interpolation RegExp built from the data's keys: only `<%= KEY.path %>` of known keys are
     * replaced.
     *
     * @param array<string, mixed> $data
     */
    public function template(mixed $layout, array $data): string
    {
        $allowedTemplateVariables = Objects::keysDeep($data);

        // Create a strict interpolation RegExp based on possible variable names
        $interpolate = Template::createStrictInterpolationRegExp($allowedTemplateVariables);

        try {
            // lodash: `_.template(undefined)` renders ''
            $layout = $layout === null ? '' : (is_scalar($layout) ? (string) $layout : throw new \TypeError('Invalid template'));

            $result = preg_replace_callback($interpolate, static function (array $match) use ($data): string {
                $value = Objects::get($data, trim($match[1]));

                return self::toTemplateString($value);
            }, $layout);
            if ($result === null) {
                throw new \RuntimeException('Invalid template');
            }

            return $result;
        } catch (\Throwable) {
            throw new ApplicationError('Invalid email template');
        }
    }

    /** `((__t = (value)) == null ? '' : __t)` concatenated to a string. */
    private static function toTemplateString(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value === true => 'true',
            $value === false => 'false',
            is_float($value) && floor($value) === $value && abs($value) < 1e21 => (string) (int) $value,
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) => implode(',', array_map(self::toTemplateString(...), $value)),
            default => '[object Object]',
        };
    }
}
