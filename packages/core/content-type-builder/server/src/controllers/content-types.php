<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers;

use Strapi\ContentTypeBuilder\Controllers\Validation\ContentType as ContentTypeValidation;
use Strapi\ContentTypeBuilder\Services\ContentTypes as ContentTypesService;
use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaHandler;
use Strapi\ContentTypeBuilder\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/controllers/content-types.ts.
 *
 * Upstream defers `strapi.reload()` until the telemetry request settles (`setImmediate`); the
 * PHP reloader only signals (see the package README, "Reload"), so it is called once the files
 * are written.
 */
final class ContentTypes
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return ContentTypesService */
    private function service(): object
    {
        /** @var ContentTypesService $service */
        $service = Utils::getService('content-types', $this->strapi);

        return $service;
    }

    public function getContentTypes(Context $ctx): mixed
    {
        $kind = $ctx->query()['kind'] ?? null;

        try {
            ContentTypeValidation::validateKind($kind);
        } catch (ApplicationError $error) {
            self::send($ctx, ['error' => self::serializeError($error)], 400);

            return null;
        }

        $contentTypes = [];
        foreach ($this->strapi->contentTypes() as $contentType) {
            if ($kind === null || ($contentType->kind ?? 'collectionType') === $kind) {
                $contentTypes[] = ContentTypesService::formatContentType($contentType);
            }
        }

        self::send($ctx, ['data' => $contentTypes]);

        return null;
    }

    public function getContentType(Context $ctx): mixed
    {
        $uid = (string) $ctx->param('uid');

        $contentType = $this->strapi->contentTypes()[$uid] ?? null;

        if ($contentType === null || !ContentTypesService::isContentTypeVisible($contentType)) {
            self::send($ctx, ['error' => 'contentType.notFound'], 404);

            return null;
        }

        self::send($ctx, ['data' => ContentTypesService::formatContentType($contentType)]);

        return null;
    }

    public function createContentType(Context $ctx): mixed
    {
        $body = $ctx->requestBody();

        try {
            ContentTypeValidation::validateContentTypeInput($body);
        } catch (ApplicationError $error) {
            self::send($ctx, ['error' => self::serializeError($error)], 400);

            return null;
        }

        /** @var array<string, mixed> $body */
        try {
            $this->strapi->reload()->setWatching(false);

            $contentType = $this->service()->createContentType([
                'contentType' => $body['contentType'] ?? null,
                'components' => $body['components'] ?? null,
            ]);

            $metricsPayload = [
                'eventProperties' => [
                    'kind' => $contentType->kind(),
                ],
            ];

            $this->strapi->telemetry()->send($this->strapi->apis() === [] ? 'didCreateFirstContentType' : 'didCreateContentType', $metricsPayload);

            $this->strapi->reload()->reload();

            self::send($ctx, ['data' => ['uid' => $contentType->uid()]], 201);
        } catch (\Throwable $err) {
            $this->strapi->log()->error($err->getMessage());
            try {
                $this->strapi->telemetry()->send('didNotCreateContentType', [
                    'eventProperties' => ['error' => $err->getMessage()],
                ]);
            } catch (\Throwable) {
            }
            self::send($ctx, ['error' => $err->getMessage() !== '' ? $err->getMessage() : 'Unknown error'], 400);
        }

        return null;
    }

    public function updateContentType(Context $ctx): mixed
    {
        $uid = (string) $ctx->param('uid');
        $body = $ctx->requestBody();

        if (!array_key_exists($uid, $this->strapi->contentTypes())) {
            self::send($ctx, ['error' => 'contentType.notFound'], 404);

            return null;
        }

        try {
            $body = ContentTypeValidation::validateUpdateContentTypeInput($body);
        } catch (ApplicationError $error) {
            self::send($ctx, ['error' => self::serializeError($error)], 400);

            return null;
        }

        /** @var array<string, mixed> $body */
        try {
            $this->strapi->reload()->setWatching(false);

            $component = $this->service()->editContentType($uid, [
                'contentType' => $body['contentType'] ?? null,
                'components' => $body['components'] ?? null,
            ]);

            $this->strapi->reload()->reload();

            self::send($ctx, ['data' => ['uid' => $component->uid()]], 201);
        } catch (\Throwable $error) {
            $this->strapi->log()->error($error->getMessage());
            self::send($ctx, ['error' => $error->getMessage() !== '' ? $error->getMessage() : 'Unknown error'], 400);
        }

        return null;
    }

    public function deleteContentType(Context $ctx): mixed
    {
        $uid = (string) $ctx->param('uid');

        if (!array_key_exists($uid, $this->strapi->contentTypes())) {
            self::send($ctx, ['error' => 'contentType.notFound'], 404);

            return null;
        }

        try {
            $this->strapi->reload()->setWatching(false);

            $component = $this->service()->deleteContentType($uid);

            $this->strapi->reload()->reload();

            self::send($ctx, ['data' => ['uid' => $component->uid()]]);
        } catch (\Throwable $error) {
            $this->strapi->log()->error($error->getMessage());
            self::send($ctx, ['error' => $error->getMessage() !== '' ? $error->getMessage() : 'Unknown error'], 400);
        }

        return null;
    }

    /**
     * `ctx.send(data, status)`, with the schema objects PHP holds as arrays encoded as JSON objects.
     */
    public static function send(Context $ctx, mixed $data, int $status = 200): void
    {
        $ctx->setBody(SchemaHandler::jsonValue($data));
        $ctx->setStatus($status);
    }

    /**
     * An error as `JSON.stringify` serializes a thrown Strapi error (its own enumerable
     * properties: `name`, `message`, `details`).
     *
     * @return array{name: string, message: string, details: array<string, mixed>}
     */
    public static function serializeError(ApplicationError $error): array
    {
        return [
            'name' => $error->name,
            'message' => $error->getMessage(),
            'details' => $error->details,
        ];
    }
}
