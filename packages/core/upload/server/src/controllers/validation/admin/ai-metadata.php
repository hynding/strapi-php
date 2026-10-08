<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers\Validation\Admin;

use Strapi\Upload\Constants;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;

/** Port of server/src/controllers/validation/admin/ai-metadata.ts. */
final class AiMetadata
{
    /** @return array{fileIds: list<int|string>} */
    public static function validateGenerateAIMetadataBody(mixed $body): array
    {
        $schema = Yup::object()
            ->shape([
                'fileIds' => Yup::array()
                    ->of(Yup::strapiID()->required())
                    ->min(1)
                    ->max(
                        Constants::AI_METADATA_MAX_FILES,
                        'You can generate metadata for up to ' . Constants::AI_METADATA_MAX_FILES . ' assets at a time. Select fewer assets and try again.',
                    )
                    ->required(),
            ])
            ->noUnknown()
            ->required();

        /** @var array{fileIds: list<int|string>} */
        return Validators::validateYupSchema($schema)($body);
    }
}
