<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers;

use Strapi\ContentTypeBuilder\Controllers\Validation\Schema as SchemaValidation;
use Strapi\ContentTypeBuilder\Services\Schema as SchemaService;
use Strapi\ContentTypeBuilder\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/controllers/schema.ts. The controller is a singleton (one per worker), so
 * `isUpdating` lives as long as the process: like upstream, it is only reset by a restart.
 */
final class Schema
{
    private bool $isUpdating = false;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return SchemaService */
    private function service(): object
    {
        /** @var SchemaService $service */
        $service = Utils::getService('schema', $this->strapi);

        return $service;
    }

    public function getSchema(Context $ctx): mixed
    {
        $schema = $this->service()->getSchema();

        ContentTypes::send($ctx, ['data' => $schema]);

        return null;
    }

    public function updateSchema(Context $ctx): mixed
    {
        if ($this->isUpdating === true) {
            $ctx->conflict('Schema update is already in progress.');

            return null;
        }

        try {
            /** @var array{data: array{components: list<array<string, mixed>>, contentTypes: list<array<string, mixed>>, contentStructure?: mixed}} $validated */
            $validated = SchemaValidation::validateUpdateSchema($ctx->requestBody());
            $data = $validated['data'];

            if (
                self::isEmpty($data['components'] ?? null)
                && self::isEmpty($data['contentTypes'] ?? null)
                && self::isEmpty($data['contentStructure'] ?? null)
            ) {
                ContentTypes::send($ctx, []);

                return null;
            }

            $this->isUpdating = true;
            $this->strapi->reload()->setWatching(false);

            $this->service()->updateSchema($data);

            // NOTE: we do not set isUpdating to false here.
            // We want to wait for the server to restart to get the isUpdate = false only
            $this->strapi->reload()->reload();

            ContentTypes::send($ctx, []);
        } catch (\Throwable $error) {
            $this->isUpdating = false;

            ContentTypes::send($ctx, ['error' => $error->getMessage()], 400);
        }

        return null;
    }

    public function getUpdateSchemaStatus(Context $ctx): mixed
    {
        ContentTypes::send($ctx, ['data' => ['isUpdating' => $this->isUpdating]]);

        return null;
    }

    /** lodash `isEmpty`. */
    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === [] || $value === '' || is_bool($value) || is_int($value) || is_float($value);
    }
}
