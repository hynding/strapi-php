<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\ConvertQueryParams;
use Strapi\Utils\QueryParamsTransformer;

/** Port of packages/core/core/src/services/query-params.ts: `strapi.get('query-params').transform(uid, params)`. */
final class QueryParams
{
    private readonly QueryParamsTransformer $transformer;

    public function __construct(Strapi $strapi)
    {
        $this->transformer = ConvertQueryParams::createTransformer(['getModel' => static fn (string $uid) => $strapi->getModel($uid)]);
    }

    public static function createQueryParamService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function transform(string $uid, array $params): array
    {
        return $this->transformer->transformQueryParams($uid, $params);
    }
}
