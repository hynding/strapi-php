<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Controller;

use Strapi\Types\Core\Context;
use Strapi\Utils\EmptyObject;
use Strapi\Utils\Errors\ValidationError;

/** Port of core-api/controller/single-type.ts: the default find/update/delete actions of a single type. */
final class SingleType extends Base
{
    /** Retrieve single type content. */
    public function find(Context $ctx): mixed
    {
        $this->validateQuery($ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx);

        $entity = $this->callService('find', $sanitizedQuery);

        $sanitizedEntity = $this->sanitizeOutput($entity, $ctx);

        return $this->transformResponse($sanitizedEntity);
    }

    /** Create or update single type content. */
    public function update(Context $ctx): mixed
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

        $entity = $this->callService('createOrUpdate', [...$sanitizedQuery, 'data' => $sanitizedInputData]);

        $sanitizedEntity = $this->sanitizeOutput($entity, $ctx);

        return $this->transformResponse($sanitizedEntity);
    }

    public function delete(Context $ctx): mixed
    {
        $this->validateQuery($ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx);

        $this->callService('delete', $sanitizedQuery);

        $ctx->setStatus(204);

        return null;
    }
}
