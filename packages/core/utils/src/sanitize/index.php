<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize;

use Strapi\Types\Schema\Schema;

/**
 * Port of packages/core/utils/src/sanitize/index.ts (`createAPISanitizers`).
 *
 * ```php
 * $sanitize = Sanitize::createAPISanitizers(['getModel' => fn (string $uid) => $strapi->getModel($uid)]);
 * $data = $sanitize->input($body, $schema, ['auth' => $ctx->state()->auth]);
 * ```
 * `Sanitize::contentAPI($getModel)` is a shorthand for the same thing.
 *
 * @phpstan-type Sanitizer callable(Schema|array<string, mixed>): (callable(mixed): mixed)
 * @phpstan-type APIOptions array{getModel: callable(string): (Schema|array<string, mixed>|null), sanitizers?: array{input?: list<callable>, output?: list<callable>}}
 */
final class Sanitize
{
    /** @param APIOptions $opts */
    public static function createAPISanitizers(array $opts): ApiSanitizers
    {
        return new ApiSanitizers($opts['getModel'], $opts['sanitizers'] ?? []);
    }

    /**
     * @param callable(string): (Schema|array<string, mixed>|null) $getModel
     * @param array{input?: list<callable>, output?: list<callable>} $sanitizers
     */
    public static function contentAPI(callable $getModel, array $sanitizers = []): ApiSanitizers
    {
        return new ApiSanitizers($getModel, $sanitizers);
    }
}
