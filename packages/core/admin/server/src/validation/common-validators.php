<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation;

use Strapi\Admin\Domain\Action\Action as ActionDomain;
use Strapi\Admin\Validation\CommonFunctions\CheckFieldsAreCorrectlyNested;
use Strapi\Admin\Validation\CommonFunctions\CheckFieldsDontHaveDuplicates;
use Strapi\Core\Core;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\TestContext;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\YupArray;
use Strapi\Utils\Yup\YupError;
use Strapi\Utils\Yup\YupObject;
use Strapi\Utils\Yup\YupString;

/**
 * Port of server/src/validation/common-validators.ts. Upstream exports schema constants; here each
 * is a static method returning a fresh schema.
 *
 * Upstream reads the global `strapi` (`strapi.plugins`, `getService('permission')`); here the
 * current {@see Core::instance()}. Unit tests stub it through {@see self::$serviceResolver} and
 * {@see self::$pluginNamesResolver}, as upstream tests stub `global.strapi`.
 */
final class CommonValidators
{
    /** @var (\Closure(string): object)|null test seam: resolves `admin::<name>` services */
    public static ?\Closure $serviceResolver = null;

    /** @var (\Closure(): list<string>)|null test seam: `Object.keys(strapi.plugins)` */
    public static ?\Closure $pluginNamesResolver = null;

    /** `getService(name)` = `strapi.service('admin::' + name)` */
    public static function getService(string $name): object
    {
        if (self::$serviceResolver !== null) {
            return (self::$serviceResolver)($name);
        }

        $strapi = Core::instance() ?? throw new \RuntimeException('Strapi is not initialized');

        return $strapi->service("admin::{$name}");
    }

    /** @return list<string> */
    private static function pluginNames(): array
    {
        if (self::$pluginNamesResolver !== null) {
            return (self::$pluginNamesResolver)();
        }

        $strapi = Core::instance();

        return $strapi === null ? [] : array_map('strval', array_keys($strapi->plugins()));
    }

    /** @return array<string, mixed>|null */
    private static function getActionFromProvider(mixed $actionId): ?array
    {
        if (!is_string($actionId)) {
            return null;
        }

        /** @var \Strapi\Admin\Services\Permission $permissionService */
        $permissionService = self::getService('permission');
        $action = $permissionService->actionProvider->get($actionId);

        return is_array($action) ? $action : null;
    }

    private static function isNil(mixed $value): bool
    {
        return $value === null || $value instanceof Undefined;
    }

    public static function email(): YupString
    {
        return Yup::string()->email()->lowercase();
    }

    public static function firstname(): YupString
    {
        return Yup::string()->trim()->min(1);
    }

    public static function lastname(): YupString
    {
        return Yup::string();
    }

    public static function username(): YupString
    {
        return Yup::string()->min(1);
    }

    public static function password(): YupString
    {
        return Yup::string()
            ->min(8)
            ->test('required-byte-size', '${path} must be less than 73 bytes', static function (mixed $value): bool {
                if (!is_string($value) || $value === '') {
                    return true;
                }

                return strlen($value) <= 72;
            })
            ->matches('/[a-z]/', '${path} must contain at least one lowercase character')
            ->matches('/[A-Z]/', '${path} must contain at least one uppercase character')
            ->matches('/\d/', '${path} must contain at least one number');
    }

    public static function roles(): YupArray
    {
        return Yup::array(Yup::strapiID())->min(1);
    }

    public static function isAPluginName(): YupString
    {
        return Yup::string()->test('is-a-plugin-name', 'is not a plugin name', static function (mixed $value, TestContext $ctx): bool|YupError {
            if ($value instanceof Undefined || in_array($value, ['admin', ...self::pluginNames()], true)) {
                return true;
            }

            return $ctx->createError(['path' => $ctx->path, 'message' => "{$ctx->path} is not an existing plugin"]);
        });
    }

    public static function arrayOfConditionNames(): YupArray
    {
        return Yup::array()
            ->of(Yup::string())
            ->test('is-an-array-of-conditions', 'is not a plugin name', static function (mixed $value, TestContext $ctx): bool|YupError {
                if ($value instanceof Undefined) {
                    return true;
                }

                /** @var \Strapi\Admin\Services\Permission $permissionService */
                $permissionService = self::getService('permission');
                $ids = $permissionService->conditionProvider->keys();

                if (is_array($value) && array_diff($value, $ids) === []) {
                    return true;
                }

                return $ctx->createError(['path' => $ctx->path, 'message' => "contains conditions that don't exist"]);
            });
    }

    public static function permissionsAreEquals(mixed $a, mixed $b): bool
    {
        $aAction = is_array($a) ? ($a['action'] ?? null) : null;
        $bAction = is_array($b) ? ($b['action'] ?? null) : null;
        $aSubject = is_array($a) ? ($a['subject'] ?? null) : null;
        $bSubject = is_array($b) ? ($b['subject'] ?? null) : null;

        return $aAction === $bAction && ($aSubject === $bSubject || ($aSubject === null && $bSubject === null));
    }

    private static function checkNoDuplicatedPermissions(mixed $permissions): bool
    {
        if (!is_array($permissions) || !array_is_list($permissions)) {
            return true;
        }

        $count = count($permissions);
        for ($i = 0; $i < $count; $i += 1) {
            for ($j = $i + 1; $j < $count; $j += 1) {
                if (self::permissionsAreEquals($permissions[$i], $permissions[$j])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $action
     * @return \Closure(mixed): bool
     */
    private static function checkNilFields(?array $action): \Closure
    {
        return static function (mixed $fields) use ($action): bool {
            // If the parent has no action field, then we ignore this test
            if ($action === null) {
                return true;
            }

            return ActionDomain::appliesToProperty('fields', $action) || self::isNil($fields);
        };
    }

    /** @param array<string, mixed>|null $action */
    private static function fieldsPropertyValidation(?array $action): YupArray
    {
        return Yup::array()
            ->of(Yup::string())
            ->nullable()
            ->test('field-nested', 'Fields format are incorrect (bad nesting).', CheckFieldsAreCorrectlyNested::checkFieldsAreCorrectlyNested(...))
            ->test('field-nested', 'Fields format are incorrect (duplicates).', CheckFieldsDontHaveDuplicates::checkFieldsDontHaveDuplicates(...))
            ->test('fields-restriction', 'The permission at ${path} must have fields set to null or undefined', self::checkNilFields($action));
    }

    private static function parentAction(TestContext $ctx): mixed
    {
        $parent = $ctx->options['parent'] ?? $ctx->parent;

        return is_array($parent) ? ($parent['action'] ?? null) : null;
    }

    public static function permission(): YupObject
    {
        return Yup::object()
            ->shape([
                'action' => Yup::string()
                    ->required()
                    ->test('action-validity', 'action is not an existing permission action', static function (mixed $actionId): bool {
                        // If the action field is Nil, ignore the test and let the required check handle the error
                        if (self::isNil($actionId)) {
                            return true;
                        }

                        return self::getActionFromProvider($actionId) !== null;
                    }),
                'actionParameters' => Yup::object()->nullable(),
                'subject' => Yup::string()
                    ->nullable()
                    ->test('subject-validity', 'Invalid subject submitted', static function (mixed $subject, TestContext $ctx): bool {
                        $action = self::getActionFromProvider(self::parentAction($ctx));

                        if ($action === null) {
                            return true;
                        }

                        $subjects = $action['subjects'] ?? null;

                        if ($subjects === null) {
                            return self::isNil($subject);
                        }

                        if (is_array($subjects) && !self::isNil($subject)) {
                            return in_array($subject, $subjects, true);
                        }

                        return false;
                    }),
                'properties' => Yup::object()
                    ->test('properties-structure', 'Invalid property set at ${path}', static function (mixed $properties, TestContext $ctx): bool {
                        $action = self::getActionFromProvider(self::parentAction($ctx));
                        $hasNoProperties = self::isNil($properties) || $properties === [] || (is_object($properties) && get_object_vars($properties) === []);

                        if ($action === null || !is_array($action['options'] ?? null) || !array_key_exists('applyToProperties', $action['options'])) {
                            return $hasNoProperties;
                        }

                        if ($hasNoProperties) {
                            return true;
                        }

                        $applyToProperties = $action['options']['applyToProperties'];

                        if (!is_array($applyToProperties)) {
                            return false;
                        }

                        foreach (array_keys(is_array($properties) ? $properties : (array) $properties) as $property) {
                            if (!in_array((string) $property, $applyToProperties, true)) {
                                return false;
                            }
                        }

                        return true;
                    })
                    ->test('fields-property', 'Invalid fields property at ${path}', static function (mixed $properties, TestContext $ctx): bool {
                        if ($properties instanceof Undefined) {
                            $properties = [];
                        }

                        $action = self::getActionFromProvider(self::parentAction($ctx));

                        if ($action === null || $properties === null) {
                            return true;
                        }

                        if (!ActionDomain::appliesToProperty('fields', $action)) {
                            return true;
                        }

                        $fields = is_array($properties) && array_key_exists('fields', $properties) ? $properties['fields'] : Undefined::value();

                        try {
                            self::fieldsPropertyValidation($action)->validate($fields, ['strict' => true, 'abortEarly' => false]);

                            return true;
                        } catch (YupError $e) {
                            // Propagate fieldsPropertyValidation error with updated path
                            throw $ctx->createError(['message' => $e->getMessage(), 'path' => "{$ctx->path}.fields"]);
                        }
                    }),
                'conditions' => Yup::array()->of(Yup::string()),
            ])
            ->noUnknown();
    }

    public static function updatePermissions(): YupObject
    {
        return Yup::object()
            ->shape([
                'permissions' => Yup::array()
                    ->required()
                    ->of(self::permission())
                    ->test('duplicated-permissions', 'Some permissions are duplicated (same action and subject)', self::checkNoDuplicatedPermissions(...)),
            ])
            ->required()
            ->noUnknown();
    }
}
