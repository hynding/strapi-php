<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers\Validation;

use Strapi\ContentManager\Validation\Zod;
use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodType;

/** Port of server/src/controllers/validation/dimensions.ts. */
final class Dimensions
{
    private static function singleLocaleSchema(): ZodType
    {
        return z::string()->nullable()->optional();
    }

    private static function multipleLocaleSchema(): ZodType
    {
        return z::union([z::array(z::string()), z::string()->nullable()])->optional();
    }

    private static function statusSchema(): ZodType
    {
        return z::enum(['draft', 'published'], ['error' => 'Invalid status'])->optional();
    }

    /**
     * From a request or query object, validates and returns the locale and status of the document.
     * If the status is not provided and Draft & Publish is disabled, it defaults to 'published'.
     *
     * Returns `['locale' => ..., 'status' => ..., ...rest]`; a `null` locale / status is
     * upstream's `undefined` (or `null`).
     *
     * @param array{allowMultipleLocales?: bool} $opts
     * @return array<string, mixed> `locale` and `status` are always set
     */
    public static function getDocumentLocaleAndStatus(Strapi $strapi, mixed $request, string $model, array $opts = ['allowMultipleLocales' => false]): array
    {
        $allowMultipleLocales = $opts['allowMultipleLocales'] ?? false;
        $request = is_array($request) ? $request : [];
        $locale = $request['locale'] ?? null;
        $providedStatus = array_key_exists('status', $request) ? $request['status'] : \Strapi\Utils\Zod\Undefined::Value;
        $rest = array_diff_key($request, ['locale' => true, 'status' => true]);

        $defaultStatus = ContentTypes::hasDraftAndPublish($strapi->getModel($model))
            ? null
            : 'published';
        $status = $providedStatus !== \Strapi\Utils\Zod\Undefined::Value ? $providedStatus : $defaultStatus;

        $schema = z::object([
            'locale' => $allowMultipleLocales ? self::multipleLocaleSchema() : self::singleLocaleSchema(),
            'status' => self::statusSchema(),
        ]);

        Zod::validateZodAsync($schema)($request);

        $result = ['locale' => $locale, 'status' => $status];
        foreach ($rest as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
