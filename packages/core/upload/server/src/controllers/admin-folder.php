<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Upload\Constants;
use Strapi\Upload\Controllers\Validation\Admin\Folder as FolderValidation;
use Strapi\Upload\Utils\Utils;

/** Port of server/src/controllers/admin-folder.ts. */
final class AdminFolder
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * lodash/fp `defaultsDeep(defaults, object)`: `object` wins, `defaults` fill what it lacks.
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    public static function defaultsDeep(array $defaults, array $object): array
    {
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $object) || $object[$key] === null) {
                $object[$key] = $value;
            } elseif (is_array($object[$key]) && is_array($value) && !array_is_list($value) && ($object[$key] === [] || !array_is_list($object[$key]))) {
                // (keys merged into a JavaScript array become properties its consumers never read:
                // a list is kept as it is)
                $object[$key] = self::defaultsDeep($value, $object[$key]);
            }
        }

        return $object;
    }

    private function permissionsManager(Context $ctx): \Strapi\Admin\Services\Permission\PermissionsManager\PermissionsManager
    {
        return Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $ctx->state()->get('userAbility'),
            'model' => Constants::FOLDER_MODEL_UID,
        ]);
    }

    public function findOne(Context $ctx): void
    {
        $id = $ctx->param('id');

        $permissionsManager = $this->permissionsManager($ctx);

        $permissionsManager->validateQuery($ctx->query());
        $query = $permissionsManager->sanitizeQuery($ctx->query());

        $result = $this->strapi->db()->query(Constants::FOLDER_MODEL_UID)->findPage(
            $this->strapi->get('query-params')->transform(
                Constants::FOLDER_MODEL_UID,
                self::defaultsDeep(
                    [
                        'filters' => ['id' => $id],
                        'populate' => [
                            'children' => ['count' => true],
                            'files' => ['count' => true],
                        ],
                    ],
                    is_array($query) ? $query : [],
                ),
            ),
        );
        $results = $result['results'];

        if ($results === []) {
            $ctx->notFound('folder not found');

            return;
        }

        $ctx->setBody([
            'data' => $permissionsManager->sanitizeOutput($results[0]),
        ]);
    }

    public function find(Context $ctx): void
    {
        $permissionsManager = $this->permissionsManager($ctx);

        $permissionsManager->validateQuery($ctx->query());
        $query = $permissionsManager->sanitizeQuery($ctx->query());

        $results = $this->strapi->db()->query(Constants::FOLDER_MODEL_UID)->findMany(
            $this->strapi->get('query-params')->transform(
                Constants::FOLDER_MODEL_UID,
                self::defaultsDeep(
                    [
                        'populate' => [
                            'children' => ['count' => true],
                            'files' => ['count' => true],
                        ],
                    ],
                    is_array($query) ? $query : [],
                ),
            ),
        );

        $ctx->setBody([
            'data' => $permissionsManager->sanitizeOutput(array_values($results)),
        ]);
    }

    public function create(Context $ctx): void
    {
        $user = $ctx->state()->user();
        $body = $ctx->requestBody();

        FolderValidation::validateCreateFolder($this->strapi, $body);

        $folderService = Utils::getService('folder', $this->strapi);

        $folder = $folderService->create(is_array($body) ? $body : [], ['user' => $user]);

        $permissionsManager = $this->permissionsManager($ctx);

        $ctx->created([
            'data' => $permissionsManager->sanitizeOutput($folder),
        ]);
    }

    public function update(Context $ctx): void
    {
        $id = (string) $ctx->param('id');
        $user = $ctx->state()->user();
        $body = $ctx->requestBody();

        $permissionsManager = $this->permissionsManager($ctx);

        FolderValidation::validateUpdateFolder($this->strapi, $id, $body);

        $folderService = Utils::getService('folder', $this->strapi);

        $updatedFolder = $folderService->update($id, is_array($body) ? $body : [], ['user' => $user]);

        if ($updatedFolder === null) {
            $ctx->notFound('folder not found');

            return;
        }

        $ctx->setBody([
            'data' => $permissionsManager->sanitizeOutput($updatedFolder),
        ]);
    }

    public function getStructure(Context $ctx): void
    {
        $structure = Utils::getService('folder', $this->strapi)->getStructure();

        $ctx->setBody([
            'data' => $structure,
        ]);
    }
}
