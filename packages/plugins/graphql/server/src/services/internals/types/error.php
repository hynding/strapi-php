<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Types;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/services/internals/types/error.ts: build an Error object type */
final class Error
{
    public static function create(): ObjectTypeDef
    {
        return Nexus::objectType([
            'name' => Constants::ERROR_TYPE_NAME,

            'definition' => static function (OutputDefinitionBlock $t): void {
                $t->nonNull->string('code', [
                    'resolve' => static function (mixed $parent): mixed {
                        $code = is_array($parent) ? ($parent['code'] ?? null) : null;

                        $isValidPlaceholderCode = in_array($code, array_values(Constants::ERROR_CODES), true);
                        if (!$isValidPlaceholderCode) {
                            $printed = is_scalar($code) ? (string) $code : 'undefined';

                            throw new ValidationError("\"{$printed}\" is not a valid code value");
                        }

                        return $code;
                    },
                ]);

                $t->string('message');
            },
        ]);
    }
}
