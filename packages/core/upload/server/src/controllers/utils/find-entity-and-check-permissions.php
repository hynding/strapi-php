<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers\Utils;

use Strapi\Admin\Services\Permission\PermissionsManager\PermissionsManager;
use Strapi\Core\Strapi;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\NotFoundError;

/** Port of server/src/controllers/utils/find-entity-and-check-permissions.ts. */
final class FindEntityAndCheckPermissions
{
    /**
     * Loads a file and enforces the permission *conditions* attached to `action` on it.
     *
     * `createdBy` and the creator's `roles` have to be populated before `toSubject`: the admin
     * conditions read them (`admin::is-creator` matches `createdBy.id`, `admin::has-same-role-as-creator`
     * matches `createdBy.roles`), and a subject missing those fields can never satisfy the rule — so
     * a conditioned grant would be denied its own files rather than allowed them.
     *
     * @return array{pm: PermissionsManager, file: array<string, mixed>}
     */
    public static function findEntityAndCheckPermissions(Strapi $strapi, mixed $ability, string $action, string $model, int|string $id): array
    {
        $file = Utils::getService('upload', $strapi)->findOne($id, [
            ContentTypes::CREATED_BY_ATTRIBUTE,
            'folder',
        ]);

        if ($file === null) {
            throw new NotFoundError();
        }

        $pm = Utils::permissionService($strapi)->createPermissionsManager(['ability' => $ability, 'action' => $action, 'model' => $model]);

        $creator = $file[ContentTypes::CREATED_BY_ATTRIBUTE] ?? null;
        $creatorId = is_array($creator) ? ($creator['id'] ?? null) : null;
        $author = $creatorId
            ? Utils::adminUserService($strapi)->findOne($creatorId, ['roles'])
            : null;

        $fileWithRoles = [...$file, 'createdBy' => $author];

        if ($pm->ability->cannot((string) $pm->action, $pm->toSubject($fileWithRoles))) {
            throw new ForbiddenError();
        }

        return ['pm' => $pm, 'file' => $file];
    }
}
