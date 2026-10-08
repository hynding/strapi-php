<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils\Sanitize;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Utils\Sanitize\Visitors\RemoveUserRelationFromRoleEntities;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\TraverseEntity;

/**
 * Port of server/src/utils/sanitize/sanitizers.js. Upstream's curried functions read the global
 * `strapi` for `getModel`; here it is the first argument.
 */
final class Sanitizers
{
    /** @param Schema|array<string, mixed> $schema */
    public static function sanitizeUserRelationFromRoleEntities(Strapi $strapi, Schema|array $schema, mixed $entity): mixed
    {
        return TraverseEntity::traverse(
            new RemoveUserRelationFromRoleEntities(),
            ['schema' => $schema, 'getModel' => static fn (string $uid): ?Schema => $strapi->getModel($uid)],
            $entity,
        );
    }

    /** @param Schema|array<string, mixed> $schema */
    public static function defaultSanitizeOutput(Strapi $strapi, Schema|array $schema, mixed $entity): mixed
    {
        return self::sanitizeUserRelationFromRoleEntities($strapi, $schema, $entity);
    }

    /**
     * The curried `defaultSanitizeOutput(schema)(entity)` form registered as a `content-api.output` sanitizer.
     *
     * @return \Closure(Schema|array<string, mixed>): (\Closure(mixed): mixed)
     */
    public static function defaultSanitizeOutputSanitizer(Strapi $strapi): \Closure
    {
        return static fn (Schema|array $schema): \Closure => static fn (mixed $entity): mixed => self::defaultSanitizeOutput($strapi, $schema, $entity);
    }
}
