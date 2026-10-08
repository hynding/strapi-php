<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services\Sanitize;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\TraverseEntity;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/** Port of server/src/services/sanitize/index.ts. */
final class Sanitize
{
    private const array LOCALIZATION_FIELDS = ['locale', 'localizations'];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Sanitizes localization fields of a given entity based on its schema.
     *
     * Remove localization-related fields that are unnecessary, that is
     * for schemas that aren't localized.
     *
     * Curried upstream: called with the schema only, it returns `fn ($entity)`.
     *
     * @param Schema|array<string, mixed> $schema
     */
    public function sanitizeLocalizationFields(Schema|array $schema, mixed ...$entity): mixed
    {
        $contentTypes = Utils::contentTypes($this->strapi);
        $strapi = $this->strapi;

        $sanitize = static fn (mixed $data): mixed => TraverseEntity::traverse(
            static function (VisitorOptions $options, VisitorUtils $utils) use ($contentTypes): void {
                $isLocalized = $contentTypes->isLocalizedContentType($options->schema);
                $isLocalizationField = in_array($options->key, self::LOCALIZATION_FIELDS, true);

                if (!$isLocalized && $isLocalizationField) {
                    $utils->remove($options->key);
                }
            },
            ['schema' => $schema, 'getModel' => $strapi->getModel(...)],
            $data,
        );

        return $entity === [] ? $sanitize : $sanitize($entity[0]);
    }
}
