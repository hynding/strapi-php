<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils\Sanitize\Visitors;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/** Port of server/src/utils/sanitize/visitors/remove-user-relation-from-role-entities.js. */
final class RemoveUserRelationFromRoleEntities
{
    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        $attribute = $options->attribute;
        $schema = $options->schema;
        $uid = $schema instanceof Schema ? $schema->uid : (is_array($schema) ? ($schema['uid'] ?? null) : null);

        if (
            ($attribute['type'] ?? null) === 'relation'
            && ($attribute['target'] ?? null) === 'plugin::users-permissions.user'
            && $uid === 'plugin::users-permissions.role'
        ) {
            $utils->remove($options->key);
        }
    }
}
