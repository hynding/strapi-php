<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers;

use Strapi\ContentManager\Controllers\Validation\Validation;
use Strapi\ContentManager\Services\Utils\Store;
use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Yup\YupError;

/** Port of server/src/controllers/components.ts. */
final class Components
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function findComponents(Context $ctx): mixed
    {
        $components = Utils::getService($this->strapi, 'components')->findAllComponents();
        $dataMapper = Utils::getService($this->strapi, 'data-mapper');

        $ctx->setBody(['data' => array_map(static fn (array $component): array => $dataMapper->toDto($component), $components)]);

        return null;
    }

    public function findComponentConfiguration(Context $ctx): mixed
    {
        $uid = (string) $ctx->param('uid');

        $componentService = Utils::getService($this->strapi, 'components');

        $component = $componentService->findComponent($uid);

        if ($component === null) {
            $ctx->notFound('component.notFound');

            return null;
        }

        $configuration = $componentService->findConfiguration($component);
        $componentsConfigurations = $componentService->findComponentsConfigurations($component);

        $ctx->setBody([
            'data' => [
                'component' => Store::toJsonConfiguration($configuration),
                'components' => self::toJsonConfigurations($componentsConfigurations),
            ],
        ]);

        return null;
    }

    public function updateComponentConfiguration(Context $ctx): mixed
    {
        $uid = (string) $ctx->param('uid');
        $body = $ctx->requestBody();

        $componentService = Utils::getService($this->strapi, 'components');

        $component = $componentService->findComponent($uid);

        if ($component === null) {
            $ctx->notFound('component.notFound');

            return null;
        }

        try {
            $input = Validation::createModelConfigurationSchema($this->strapi, $component)->validate($body, [
                'abortEarly' => false,
                'stripUnknown' => true,
                'strict' => true,
            ]);
        } catch (YupError $error) {
            $ctx->badRequest(null, [
                'name' => 'validationError',
                'errors' => self::yupErrors($error),
            ]);

            return null;
        }

        $newConfiguration = $componentService->updateConfiguration($component, is_array($input) ? $input : []);

        $ctx->setBody(['data' => Store::toJsonConfiguration($newConfiguration)]);

        return null;
    }

    /** @return list<string> upstream `error.errors` (the yup messages) */
    public static function yupErrors(YupError $error): array
    {
        return array_values(array_map(static fn (mixed $e): string => is_string($e) ? $e : \Strapi\Utils\Primitives\Strings::stringify($e), $error->errors));
    }

    /**
     * @param array<string, array<string, mixed>> $configurations
     * @return array<string, array<string, mixed>>|\stdClass
     */
    public static function toJsonConfigurations(array $configurations): array|\stdClass
    {
        if ($configurations === []) {
            return new \stdClass();
        }

        return array_map(static fn (array $configuration): array => Store::toJsonConfiguration($configuration), $configurations);
    }
}
