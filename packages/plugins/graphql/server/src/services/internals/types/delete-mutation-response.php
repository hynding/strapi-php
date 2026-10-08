<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Types;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;

/** Port of server/src/services/internals/types/delete-mutation-response.ts */
final class DeleteMutationResponse
{
    /** @return array{DeleteMutationResponse: ObjectTypeDef} */
    public static function create(): array
    {
        return [
            'DeleteMutationResponse' => Nexus::objectType([
                'name' => Constants::DELETE_MUTATION_RESPONSE_TYPE_NAME,

                'definition' => static function (OutputDefinitionBlock $t): void {
                    $t->nonNull->id('documentId');
                },
            ]),
        ];
    }
}
