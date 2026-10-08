<?php

declare(strict_types=1);

namespace Strapi\Upload\Routes\Validation;

use Strapi\Core\Strapi;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodArray;
use Strapi\Utils\Zod\ZodNumber;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of server/src/routes/validation/upload.ts: validation for upload/file routes.
 *
 * Upstream extends `@strapi/utils`' `AbstractRouteValidator` for the common query parameters
 * (`fields`, `populate`, `sort`, `pagination`, `filters`); that class is not ported, so those
 * schemas are permissive here (core only reads the declared query keys, for strictParams).
 */
final class UploadRouteValidator
{
    public function __construct(protected readonly ?Strapi $strapi = null)
    {
    }

    /** File schema for upload responses */
    public function file(): ZodObject
    {
        return z::object([
            'id' => $this->fileId(),
            'documentId' => z::uuid(),
            'name' => z::string(),
            'alternativeText' => z::string()->nullable()->optional(),
            'caption' => z::string()->nullable()->optional(),
            'width' => z::number()->int()->optional(),
            'height' => z::number()->int()->optional(),
            'formats' => z::record(z::string(), z::unknown())->optional(),
            'hash' => z::string(),
            'ext' => z::string()->optional(),
            'mime' => z::string(),
            'size' => z::number(),
            'url' => z::string(),
            'previewUrl' => z::string()->nullable()->optional(),
            'folder' => z::number()->optional(),
            'folderPath' => z::string(),
            'provider' => z::string(),
            'provider_metadata' => z::record(z::string(), z::unknown())->nullable()->optional(),
            'createdAt' => z::string(),
            'updatedAt' => z::string(),
            'createdBy' => z::number()->optional(),
            'updatedBy' => z::number()->optional(),
        ]);
    }

    /** Array of files schema */
    public function files(): ZodArray
    {
        return z::array($this->file());
    }

    /** Paginated files response schema: `{ data, meta: { pagination } }`. */
    public function paginatedFiles(): ZodObject
    {
        $pagedPagination = z::object([
            'page' => z::number()->int(),
            'pageSize' => z::number()->int(),
            'pageCount' => z::number()->int()->optional(),
            'total' => z::number()->int()->optional(),
        ]);

        $offsetPagination = z::object([
            'start' => z::number()->int(),
            'limit' => z::number()->int(),
            'total' => z::number()->int()->optional(),
        ]);

        return z::object([
            'data' => $this->files(),
            'meta' => z::object([
                'pagination' => z::union([$pagedPagination, $offsetPagination]),
            ]),
        ]);
    }

    /** File ID parameter validation */
    public function fileId(): ZodNumber
    {
        return z::number()->int()->positive();
    }

    /** Upload request body schema for single file uploads */
    public function uploadBody(): ZodObject
    {
        return z::object([
            'fileInfo' => z::object([
                'name' => z::string()->optional(),
                'alternativeText' => z::string()->optional(),
                'caption' => z::string()->optional(),
            ])->optional(),
        ]);
    }

    /** Upload request body schema for multiple file uploads */
    public function multiUploadBody(): ZodObject
    {
        return z::object([
            'fileInfo' => z::array(
                z::object([
                    'name' => z::string()->optional(),
                    'alternativeText' => z::string()->optional(),
                    'caption' => z::string()->optional(),
                ]),
            )->optional(),
        ]);
    }

    // AbstractRouteValidator's query parameter schemas

    public function queryFields(): ZodType
    {
        return z::any();
    }

    public function queryPopulate(): ZodType
    {
        return z::any();
    }

    public function querySort(): ZodType
    {
        return z::any();
    }

    public function pagination(): ZodType
    {
        return z::any();
    }

    public function filters(): ZodType
    {
        return z::any();
    }
}
