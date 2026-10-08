<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers;

use Strapi\ContentManager\Controllers\Validation\Dimensions;
use Strapi\ContentManager\Controllers\Validation\Validation;
use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/** Port of server/src/controllers/uid.ts. */
final class Uid
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function generateUID(Context $ctx): mixed
    {
        ['contentTypeUID' => $contentTypeUID, 'field' => $field, 'data' => $data] = Validation::validateGenerateUIDInput($ctx->requestBody());

        $query = $ctx->query();
        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $query, $contentTypeUID);

        Validation::validateUIDField($this->strapi, $contentTypeUID, $field);

        $uidService = Utils::getService($this->strapi, 'uid');

        $ctx->setBody([
            'data' => $uidService->generateUIDField([
                'contentTypeUID' => $contentTypeUID,
                'field' => $field,
                'data' => $data,
                'locale' => is_string($locale) ? $locale : null,
            ]),
        ]);

        return null;
    }

    public function checkUIDAvailability(Context $ctx): mixed
    {
        ['contentTypeUID' => $contentTypeUID, 'field' => $field, 'value' => $value] = Validation::validateCheckUIDAvailabilityInput(
            $this->strapi,
            $ctx->requestBody(),
        );

        $query = $ctx->query();
        ['locale' => $locale] = Dimensions::getDocumentLocaleAndStatus($this->strapi, $query, $contentTypeUID);

        Validation::validateUIDField($this->strapi, $contentTypeUID, $field);

        $uidService = Utils::getService($this->strapi, 'uid');

        $params = [
            'contentTypeUID' => $contentTypeUID,
            'field' => $field,
            'value' => $value,
            'locale' => is_string($locale) ? $locale : null,
        ];
        $isAvailable = $uidService->checkUIDAvailability($params);

        $ctx->setBody([
            'isAvailable' => $isAvailable,
            'suggestion' => !$isAvailable ? $uidService->findUniqueUID($params) : null,
        ]);

        return null;
    }
}
