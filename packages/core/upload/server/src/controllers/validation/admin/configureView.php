<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers\Validation\Admin;

use Strapi\Upload\Constants;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;

/** Port of server/src/controllers/validation/admin/configureView.ts. */
final class ConfigureView
{
    /** @return array<string, mixed> */
    public static function validateViewConfiguration(mixed $body): array
    {
        $configSchema = Yup::object([
            'pageSize' => Yup::number()->required(),
            'sort' => Yup::mixed()->oneOf(Constants::ALLOWED_SORT_STRINGS),
        ]);

        $result = Validators::validateYupSchema($configSchema)($body);

        return is_array($result) ? $result : [];
    }
}
