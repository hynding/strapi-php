<?php

declare(strict_types=1);

namespace Strapi\Utils\Validate;

use Strapi\Types\Schema\Schema;

/**
 * Port of packages/core/utils/src/validate/index.ts (`createAPIValidators`).
 *
 * ```php
 * $validate = Validate::createAPIValidators(['getModel' => fn (string $uid) => $strapi->getModel($uid)]);
 * $validate->query($ctx->query(), $schema, ['auth' => $ctx->state()->auth]); // throws ValidationError
 * ```
 *
 * @phpstan-type APIOptions array{getModel: callable(string): (Schema|array<string, mixed>|null), validators?: array{input?: list<callable>}}
 */
final class Validate
{
    /** @param APIOptions $opts */
    public static function createAPIValidators(array $opts): ApiValidators
    {
        return new ApiValidators($opts['getModel'], $opts['validators'] ?? []);
    }

    /**
     * @param callable(string): (Schema|array<string, mixed>|null) $getModel
     * @param array{input?: list<callable>} $validators
     */
    public static function contentAPI(callable $getModel, array $validators = []): ApiValidators
    {
        return new ApiValidators($getModel, $validators);
    }
}
