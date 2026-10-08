<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Controller;

use Strapi\Types\Core\Context;
use Strapi\Utils\EmptyObject;
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

        // a JSON `{}` stays an EmptyObject in component, dynamic-zone and json values, so the
        // entity validator tells it from `[]` (see Strapi\Utils\EmptyObject)
        $body = $ctx->requestBody(true);
        $body = is_array($body) ? $body : [];
        if (($body['data'] ?? null) instanceof EmptyObject) {
            $body['data'] = [];
        }
        if (array_key_exists('data', $body)) {
            $body['data'] = EmptyObject::keepInDocumentData($body['data'], $this->contentType, $this->strapi->getModel(...));
        }

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

        // a JSON `{}` stays an EmptyObject in component, dynamic-zone and json values, so the
        // entity validator tells it from `[]` (see Strapi\Utils\EmptyObject)
        $body = $ctx->requestBody(true);
        $body = is_array($body) ? $body : [];
        if (($body['data'] ?? null) instanceof EmptyObject) {
            $body['data'] = [];
        }
        if (array_key_exists('data', $body)) {
            $body['data'] = EmptyObject::keepInDocumentData($body['data'], $this->contentType, $this->strapi->getModel(...));
        }

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
