<?php

declare(strict_types=1);

namespace Strapi\Core\Migrations;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/migrations/index.ts: the content-type sync migrations run from
 * the `strapi::content-types.beforeSync` / `afterSync` hooks.
 *
 * @phpstan-type Input array{oldContentTypes: array<string, mixed>|null, contentTypes: array<string, \Strapi\Types\Schema\Schema>}
 */
final class Migrations
{
    /** @param Input $input */
    public static function enable(Strapi $strapi, array $input): void
    {
        I18n::enable($strapi, $input);
        DraftPublish::enable($strapi, $input);
        FirstPublishedAt::enable($strapi, $input);
    }

    /** @param Input $input */
    public static function disable(Strapi $strapi, array $input): void
    {
        I18n::disable($strapi, $input);
        DraftPublish::disable($strapi, $input);
    }
}
