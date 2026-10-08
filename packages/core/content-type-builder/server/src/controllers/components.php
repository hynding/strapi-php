<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers;

use Strapi\ContentTypeBuilder\Controllers\Validation\Component as ComponentValidation;
use Strapi\ContentTypeBuilder\Services\Components as ComponentsService;
use Strapi\ContentTypeBuilder\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;

/** Port of server/src/controllers/components.ts. */
final class Components
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return ComponentsService */
    private function service(): object
    {
        /** @var ComponentsService $service */
        $service = Utils::getService('components', $this->strapi);

        return $service;
    }

    /**
     * GET /components handler
     * Returns a list of available components
     */
    public function getComponents(Context $ctx): mixed
    {
        $data = [];
        foreach ($this->strapi->components() as $component) {
            $data[] = ComponentsService::formatComponent($component);
        }

        ContentTypes::send($ctx, ['data' => $data]);

        return null;
    }

    /**
     * GET /components/:uid
     * Returns a specific component
     */
    public function getComponent(Context $ctx): mixed
    {
        $uid = (string) $ctx->param('uid');

        $component = $this->strapi->components()[$uid] ?? null;

        if ($component === null) {
            ContentTypes::send($ctx, ['error' => 'component.notFound'], 404);

            return null;
        }

        ContentTypes::send($ctx, ['data' => ComponentsService::formatComponent($component)]);

        return null;
    }

    /**
     * POST /components
     * Creates a component and returns its infos
     */
    public function createComponent(Context $ctx): mixed
    {
        $body = $ctx->requestBody();

        try {
            ComponentValidation::validateComponentInput($body);
        } catch (ApplicationError $error) {
            ContentTypes::send($ctx, ['error' => ContentTypes::serializeError($error)], 400);

            return null;
        }

        /** @var array<string, mixed> $body */
        try {
            $this->strapi->reload()->setWatching(false);

            $component = $this->service()->createComponent([
                'component' => $body['component'] ?? null,
                'components' => $body['components'] ?? null,
            ]);

            $this->strapi->reload()->reload();

            ContentTypes::send($ctx, ['data' => ['uid' => $component->uid()]], 201);
        } catch (\Throwable $error) {
            $this->strapi->log()->error($error->getMessage());
            ContentTypes::send($ctx, ['error' => $error->getMessage() !== '' ? $error->getMessage() : 'Unknown error'], 400);
        }

        return null;
    }

    /**
     * PUT /components/:uid
     * Updates a component and return its infos
     */
    public function updateComponent(Context $ctx): mixed
    {
        $uid = (string) $ctx->param('uid');
        $body = $ctx->requestBody();

        if (!array_key_exists($uid, $this->strapi->components())) {
            ContentTypes::send($ctx, ['error' => 'component.notFound'], 404);

            return null;
        }

        try {
            $body = ComponentValidation::validateUpdateComponentInput($body);
        } catch (ApplicationError $error) {
            ContentTypes::send($ctx, ['error' => ContentTypes::serializeError($error)], 400);

            return null;
        }

        /** @var array<string, mixed> $body */
        try {
            $this->strapi->reload()->setWatching(false);

            $component = $this->service()->editComponent($uid, [
                'component' => $body['component'] ?? null,
                'components' => $body['components'] ?? null,
            ]);

            $this->strapi->reload()->reload();

            ContentTypes::send($ctx, ['data' => ['uid' => $component->uid()]]);
        } catch (\Throwable $error) {
            $this->strapi->log()->error($error->getMessage());
            ContentTypes::send($ctx, ['error' => $error->getMessage() !== '' ? $error->getMessage() : 'Unknown error'], 400);
        }

        return null;
    }

    /**
     * DELETE /components/:uid
     * Deletes a components and returns its old infos
     */
    public function deleteComponent(Context $ctx): mixed
    {
        $uid = (string) $ctx->param('uid');

        if (!array_key_exists($uid, $this->strapi->components())) {
            ContentTypes::send($ctx, ['error' => 'component.notFound'], 404);

            return null;
        }

        try {
            $this->strapi->reload()->setWatching(false);

            $component = $this->service()->deleteComponent($uid);

            $this->strapi->reload()->reload();

            ContentTypes::send($ctx, ['data' => ['uid' => $component->uid()]]);
        } catch (\Throwable $error) {
            $this->strapi->log()->error($error->getMessage());
            ContentTypes::send($ctx, ['error' => $error->getMessage() !== '' ? $error->getMessage() : 'Unknown error'], 400);
        }

        return null;
    }
}
