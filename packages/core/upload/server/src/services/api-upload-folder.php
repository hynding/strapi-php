<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants;
use Strapi\Upload\Utils\Utils;

/** Port of server/src/services/api-upload-folder.ts. */
final class ApiUploadFolder
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array{type: string, name: string, key: string} */
    private static function store(): array
    {
        return ['type' => 'plugin', 'name' => 'upload', 'key' => 'api-folder'];
    }

    /** @return array<string, mixed> */
    private function createApiUploadFolder(): array
    {
        $name = Constants::API_UPLOAD_FOLDER_BASE_NAME;
        $folderService = Utils::getService('folder', $this->strapi);

        $exists = true;
        $index = 1;
        while ($exists) {
            $exists = $folderService->exists(['name' => $name, 'parent' => null]);
            if ($exists) {
                $name = Constants::API_UPLOAD_FOLDER_BASE_NAME . " ({$index})";
                $index += 1;
            }
        }

        $folder = $folderService->create(['name' => $name]);

        $this->strapi->store()->set([...self::store(), 'value' => ['id' => $folder['id'] ?? null]]);

        return $folder;
    }

    /** @return array<string, mixed> */
    public function getAPIUploadFolder(): array
    {
        $storeValue = $this->strapi->store()->get(self::store());
        $folderId = is_array($storeValue) ? ($storeValue['id'] ?? null) : null;

        $folder = $folderId
            ? $this->strapi->db()->query(Constants::FOLDER_MODEL_UID)->findOne(['where' => ['id' => $folderId]])
            : null;

        return is_array($folder) ? $folder : $this->createApiUploadFolder();
    }
}
