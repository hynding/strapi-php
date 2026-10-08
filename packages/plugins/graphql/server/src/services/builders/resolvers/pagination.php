<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Resolvers;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;

/** Port of server/src/services/builders/resolvers/pagination.ts */
final class Pagination
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array{total: int, page: int, pageSize: int, pageCount: int} */
    public function resolvePagination(mixed $parent, mixed $_, mixed $ctx): array
    {
        $info = is_array($parent) && is_array($parent['info'] ?? null) ? $parent['info'] : [];
        $args = is_array($info['args'] ?? null) ? $info['args'] : [];
        $resourceUID = (string) ($info['resourceUID'] ?? '');
        $start = (int) ($args['start'] ?? 0);
        $limit = (int) ($args['limit'] ?? 0);
        $safeLimit = max($limit, 1);
        $contentType = $this->strapi->getModel($resourceUID);
        $auth = GraphqlContext::authOf($ctx);

        $this->strapi->contentAPI()->validate()->query($args, $contentType, ['auth' => $auth]);

        $sanitizedQuery = $this->strapi->contentAPI()->sanitize()->query($args, $contentType, ['auth' => $auth]);

        $publicationFilter = MergePublicationArgs::mergePublicationFilterFromGraphQLArgs($args)['publicationFilter'] ?? null;
        $status = $sanitizedQuery['status'] ?? null;
        $restSanitized = $sanitizedQuery;
        unset($restSanitized['status']);

        $total = $this->strapi->documents($resourceUID)->count([
            ...$restSanitized,
            ...($publicationFilter !== null ? ['publicationFilter' => $publicationFilter] : []),
            'status' => $status ?? 'published',
        ]);

        $pageSize = $limit === -1 ? $total - $start : $safeLimit;
        $pageCount = $limit === -1 ? $safeLimit : (int) ceil($total / $safeLimit);
        $page = $limit === -1 ? $safeLimit : (int) floor($start / $safeLimit) + 1;

        return ['total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'pageCount' => $pageCount];
    }
}
