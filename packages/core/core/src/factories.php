<?php

declare(strict_types=1);

namespace Strapi\Core;

use Strapi\Core\CoreApi\Controller\Controller;
use Strapi\Core\CoreApi\Extendable;
use Strapi\Core\CoreApi\Routes\CoreRouter;
use Strapi\Core\CoreApi\Service\Service;

/**
 * Port of packages/core/core/src/factories.ts.
 *
 * ```php
 * // src/api/article/controllers/article.php
 * return Factories::createCoreController('api::article.article', fn (Strapi $strapi) => [
 *     'find' => function (Context $ctx) { ... $this->sanitizeQuery($ctx) ... },
 * ]);
 * // src/api/article/routes/article.php
 * return Factories::createCoreRouter('api::article.article', ['only' => ['find', 'findOne']]);
 * ```
 *
 * `createCoreController` / `createCoreService` return factories (`callable(Strapi): object`) the
 * controllers/services registries instantiate lazily; `$cfg` is an array of overrides or a
 * `callable(Strapi): array`. Inside an override closure `$this` is the controller: base methods
 * (`sanitizeQuery`, `transformResponse`...) and `$this->strapi` are available.
 */
final class Factories
{
    /**
     * @param array<string, callable>|\Closure(Strapi): array<string, callable>|null $cfg
     * @return \Closure(Strapi): Extendable
     */
    public static function createCoreController(string $uid, array|\Closure|null $cfg = null): \Closure
    {
        return static function (Strapi $strapi) use ($uid, $cfg): Extendable {
            $baseController = Controller::createController($strapi, $strapi->contentType($uid));

            $userCtrl = $cfg instanceof \Closure ? $cfg($strapi) : ($cfg ?? []);

            return new Extendable($baseController, $userCtrl, $strapi, $cfg !== null);
        };
    }

    /**
     * @param array<string, callable>|\Closure(Strapi): array<string, callable>|null $cfg
     * @return \Closure(Strapi): Extendable
     */
    public static function createCoreService(string $uid, array|\Closure|null $cfg = null): \Closure
    {
        return static function (Strapi $strapi) use ($uid, $cfg): Extendable {
            $baseService = Service::createService($strapi, $strapi->contentType($uid));

            $userService = $cfg instanceof \Closure ? $cfg($strapi) : ($cfg ?? []);

            return new Extendable($baseService, $userService, $strapi, $cfg !== null);
        };
    }

    /**
     * @param array{prefix?: string, config?: array<string, array<string, mixed>>, only?: list<string>, except?: list<string>, type?: string} $cfg
     */
    public static function createCoreRouter(string $uid, array $cfg = []): CoreRouter
    {
        return new CoreRouter($uid, $cfg);
    }

    public static function isCustomController(object $controller): bool
    {
        return $controller instanceof Extendable && $controller->isCustom;
    }
}
