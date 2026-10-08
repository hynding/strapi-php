<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\YupArray;

/** Port of server/src/validation/action-provider.ts. */
final class ActionProvider
{
    public static function registerProviderActionSchema(): YupArray
    {
        return Yup::array()
            ->required()
            ->of(
                Yup::object()
                    ->shape([
                        'uid' => Yup::string()
                            ->matches(
                                '/^[a-z]([a-z|.|-]+)[a-z]$/',
                                static fn (array $v): string => "{$v['path']}: The uid can only contain lowercase letters, dots and hyphens.",
                            )
                            ->required(),
                        'section' => Yup::string()->oneOf(['contentTypes', 'plugins', 'settings', 'internal'])->required(),
                        'pluginName' => Yup::mixed()->when('section', [
                            'is' => 'plugins',
                            'then' => CommonValidators::isAPluginName()->required(),
                            'otherwise' => CommonValidators::isAPluginName(),
                        ]),
                        'subjects' => Yup::mixed()->when('section', [
                            'is' => 'contentTypes',
                            'then' => Yup::array()->of(Yup::string())->required(),
                            'otherwise' => Yup::mixed()->oneOf([Undefined::value()], 'subjects should only be defined for the "contentTypes" section'),
                        ]),
                        'displayName' => Yup::string()->required(),
                        'category' => Yup::mixed()->when('section', [
                            'is' => 'settings',
                            'then' => Yup::string()->required(),
                            'otherwise' => Yup::mixed()->test(
                                'settingsCategory',
                                'category should only be defined for the "settings" section',
                                static fn (mixed $cat): bool => $cat instanceof Undefined,
                            ),
                        ]),
                        'subCategory' => Yup::mixed()->when('section', [
                            'is' => static fn (mixed $section): bool => in_array($section, ['settings', 'plugins'], true),
                            'then' => Yup::string(),
                            'otherwise' => Yup::mixed()->test(
                                'settingsSubCategory',
                                'subCategory should only be defined for "plugins" and "settings" sections',
                                static fn (mixed $subCat): bool => $subCat instanceof Undefined,
                            ),
                        ]),
                        'options' => Yup::object([
                            'applyToProperties' => Yup::array()->of(Yup::string()),
                        ]),
                        'aliases' => Yup::array(
                            Yup::object([
                                'actionId' => Yup::string(),
                                'subjects' => Yup::array(Yup::string())->nullable(),
                            ]),
                        )->nullable(),
                    ])
                    ->noUnknown(),
            );
    }

    /**
     * @param mixed $actions
     * @throws \Strapi\Utils\Errors\YupValidationError
     */
    public static function validateRegisterProviderAction(mixed $actions, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchemaSync(self::registerProviderActionSchema())($actions, $errorMessage);
    }
}
