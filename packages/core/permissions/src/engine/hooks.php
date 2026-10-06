<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine;

use Strapi\Permissions\Domain\Permission\Permission;
use Strapi\Permissions\Engine\Hooks\BeforeEvaluateContext;
use Strapi\Permissions\Engine\Hooks\ValidateContext;
use Strapi\Permissions\Engine\Hooks\WillRegisterContext;
use Strapi\Utils\Hooks as UtilsHooks;
use Strapi\Utils\Hooks\AsyncBailHook;
use Strapi\Utils\Hooks\AsyncSeriesHook;
use Strapi\Utils\Hooks\AsyncSeriesWaterfallHook;

/**
 * Port of packages/core/permissions/src/engine/hooks.ts.
 *
 * @phpstan-type EngineHooks array{'before-format::validate.permission': AsyncBailHook, 'format.permission': AsyncSeriesWaterfallHook, 'after-format::validate.permission': AsyncBailHook, 'before-evaluate.permission': AsyncSeriesHook, 'before-register.permission': AsyncSeriesHook}
 */
final class Hooks
{
    public const HOOK_NAMES = [
        'before-format::validate.permission',
        'format.permission',
        'after-format::validate.permission',
        'before-evaluate.permission',
        'before-register.permission',
    ];

    /**
     * Create a hook map used by the permission Engine.
     *
     * @return EngineHooks
     */
    public static function createEngineHooks(): array
    {
        return [
            'before-format::validate.permission' => UtilsHooks::createAsyncBailHook(),
            'format.permission' => UtilsHooks::createAsyncSeriesWaterfallHook(),
            'after-format::validate.permission' => UtilsHooks::createAsyncBailHook(),
            'before-evaluate.permission' => UtilsHooks::createAsyncSeriesHook(),
            'before-register.permission' => UtilsHooks::createAsyncSeriesHook(),
        ];
    }

    /** @param array<string, mixed> $permission */
    public static function createValidateContext(array $permission): ValidateContext
    {
        return new ValidateContext($permission);
    }

    /**
     * Context for the before-evaluate hook: the permission is a reference so `addCondition()` mutates
     * the caller's permission, as `Object.assign(permission, ...)` does upstream.
     *
     * @param array<string, mixed> $permission
     */
    public static function createBeforeEvaluateContext(array &$permission): BeforeEvaluateContext
    {
        return new BeforeEvaluateContext($permission);
    }

    /**
     * @param array{permission: array<string, mixed>, options: array<string, mixed>} $params
     */
    public static function createWillRegisterContext(array $params): WillRegisterContext
    {
        return new WillRegisterContext($params['permission'], $params['options']);
    }

    /** Helper shared by the contexts: `Permission::addCondition`. */
    public static function addCondition(string $condition, array $permission): array
    {
        /** @var array<string, mixed> $result */
        $result = Permission::addCondition($condition, $permission);

        return $result;
    }
}
