<?php

declare(strict_types=1);

namespace Strapi\Core\Services\ContentApi\Permissions;

use Strapi\Core\Registries\ActionMap;
use Strapi\Core\Services\ContentApi\Permissions\Providers\Action;
use Strapi\Core\Services\ContentApi\Permissions\Providers\Condition;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Engine as PermissionsEngine;
use Strapi\Permissions\Engine\Hooks\BeforeEvaluateContext;

/**
 * Port of packages/core/core/src/services/content-api/permissions/index.ts: the content-API
 * permission engine (`strapi.contentAPI.permissions`).
 *
 * Upstream tags controller actions bound to a content-api route with a `__type__` symbol; here
 * the server records them in {@see self::$boundActions} (`registerBoundAction`) from compose-endpoint.
 */
final class Permissions
{
    public readonly PermissionsEngine $engine;

    /**
     * Writable, like the property of upstream's plain object (a test replaces a provider's method:
     * `strapi.contentAPI.permissions.providers.action.keys = jest.fn()`).
     *
     * @var array{action: Action, condition: Condition}
     */
    public array $providers;

    /** @var array<string, array<string, list<string>>> controllerUid => actionName => route types */
    private array $boundActions = [];

    public function __construct(private readonly Strapi $strapi)
    {
        $this->providers = [
            'action' => Action::createActionProvider($strapi),
            'condition' => Condition::createConditionProvider($strapi),
        ];

        // Create an instance of a content-API permission engine and bind a custom validation handler to it
        $this->engine = Engine::createPermissionEngine(['providers' => $this->providers]);

        $this->engine->on('before-format::validate.permission', $this->createValidatePermissionHandler(...));
    }

    public static function instantiatePermissionsUtilities(Strapi $strapi): self
    {
        return new self($strapi);
    }

    /** @param array{permission: array<string, mixed>}|BeforeEvaluateContext $params */
    private function createValidatePermissionHandler(array|BeforeEvaluateContext $params): bool
    {
        $permission = $params instanceof BeforeEvaluateContext ? $params->permission() : $params['permission'];
        $action = $this->providers['action']->get((string) ($permission['action'] ?? ''));

        // If the action isn't registered into the action provider, then ignore the permission and warn the user
        if ($action === null) {
            $this->strapi->log()->debug("Unknown action \"{$permission['action']}\" supplied when registering a new permission");

            return false;
        }

        return true;
    }

    /** Record that controller `$controllerUid`'s `$actionName` is bound to a `$type` route. */
    public function registerBoundAction(string $controllerUid, string $actionName, string $type): void
    {
        $this->boundActions[$controllerUid][$actionName][] = $type;
    }

    public function isContentApiAction(string $controllerUid, string $actionName): bool
    {
        return in_array('content-api', $this->boundActions[$controllerUid][$actionName] ?? [], true);
    }

    /**
     * Get a tree representation of the available Content API actions based on the methods of the
     * Content API controllers. Only actions bound to a content-API route are returned.
     *
     * @return array<string, array{controllers: array<string, list<string>>}>
     */
    public function getActionsMap(): array
    {
        $actionMap = [];

        $registerAPIsActions = function (array $apis, string $source) use (&$actionMap): void {
            foreach ($apis as $apiName => $api) {
                $controllers = [];
                foreach ($api->controllers() as $controllerName => $controller) {
                    $uid = "{$source}::{$apiName}.{$controllerName}";
                    $contentApiActions = array_values(array_filter(
                        ActionMap::actionNames($controller),
                        fn (string $action): bool => $this->isContentApiAction($uid, $action),
                    ));

                    if ($contentApiActions === []) {
                        continue;
                    }

                    $controllers[(string) $controllerName] = $contentApiActions;
                }

                if ($controllers !== []) {
                    $actionMap["{$source}::{$apiName}"] = ['controllers' => $controllers];
                }
            }
        };

        $registerAPIsActions($this->strapi->apis(), 'api');
        $registerAPIsActions($this->strapi->plugins(), 'plugin');

        return $actionMap;
    }

    /** Register all the content-API controllers actions into the action provider. */
    public function registerActions(): void
    {
        foreach ($this->getActionsMap() as $api => ['controllers' => $controllers]) {
            foreach ($controllers as $controller => $actions) {
                foreach ($actions as $action) {
                    $actionUID = "{$api}.{$controller}.{$action}";

                    $this->providers['action']->register($actionUID, [
                        'api' => $api,
                        'controller' => $controller,
                        'action' => $action,
                        'uid' => $actionUID,
                    ]);
                }
            }
        }
    }
}
