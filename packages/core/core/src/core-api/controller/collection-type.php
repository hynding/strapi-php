<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Controller;

use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ValidationError;

/** Port of core-api/controller/collection-type.ts: the default find/findOne/create/update/delete actions. */
final class CollectionType extends Base
{
    /** Retrieve records. */
    public function find(Context $ctx): mixed
    {
        $this->validateQuery($ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx);

        $page = $this->callService('find', $sanitizedQuery);
        \assert(is_array($page));
        ['results' => $results, 'pagination' => $pagination] = $page;
        $sanitizedResults = $this->sanitizeOutput($results, $ctx);

        return $this->transformResponse($sanitizedResults, ['pagination' => $pagination]);
    }

    /** Retrieve a record. */
    public function findOne(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $this->validateQuery($ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx);

        $entity = $this->callService('findOne', $id, $sanitizedQuery);
        $sanitizedEntity = $this->sanitizeOutput($entity, $ctx);

        return $this->transformResponse($sanitizedEntity);
    }

    /** Create a record. */
    public function create(Context $ctx): mixed
    {
        $this->validateQuery($ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx);

        $body = $ctx->requestBody();
        $body = is_array($body) ? $body : [];

        if (!is_array($body['data'] ?? null) || array_is_list($body['data'] ?? null) && ($body['data'] ?? []) !== []) {
            throw new ValidationError('Missing "data" payload in the request body');
        }

        $this->validateInput($body['data'], $ctx);

        $sanitizedInputData = $this->sanitizeInput($body['data'], $ctx);

        $entity = $this->callService('create', [...$sanitizedQuery, 'data' => $sanitizedInputData]);

        $sanitizedEntity = $this->sanitizeOutput($entity, $ctx);

        $ctx->setStatus(201);

        return $this->transformResponse($sanitizedEntity);
    }

    /** Update a record. */
    public function update(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $this->validateQuery($ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx);

        $body = $ctx->requestBody();
        $body = is_array($body) ? $body : [];

        if (!is_array($body['data'] ?? null) || array_is_list($body['data'] ?? null) && ($body['data'] ?? []) !== []) {
            throw new ValidationError('Missing "data" payload in the request body');
        }

        $this->validateInput($body['data'], $ctx);

        $sanitizedInputData = $this->sanitizeInput($body['data'], $ctx);

        $entity = $this->callService('update', $id, [...$sanitizedQuery, 'data' => $sanitizedInputData]);

        $sanitizedEntity = $this->sanitizeOutput($entity, $ctx);

        return $this->transformResponse($sanitizedEntity);
    }

    /** Destroy a record. */
    public function delete(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $this->validateQuery($ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx);

        $this->callService('delete', $id, $sanitizedQuery);

        $ctx->setStatus(204);

        return null;
    }
}
