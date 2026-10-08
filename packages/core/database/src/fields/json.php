<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

use Strapi\Utils\EmptyObject;

/** Port of packages/core/database/src/fields/json.ts. */
class JsonField extends Field
{
    public function toDB(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    public function fromDB(mixed $value): mixed
    {
        return self::parse($value, false);
    }

    /**
     * PHP port: {@see self::fromDB()}, with an empty JSON object read as an {@see EmptyObject}
     * rather than `[]`, so that a content `json` attribute stored as `{}` is answered as `{}`
     * (query/helpers/transform.php uses it for content-type and component models).
     */
    public function fromDBKeepingEmptyObjects(mixed $value): mixed
    {
        return self::parse($value, true);
    }

    private static function parse(mixed $value, bool $emptyObjects): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decode = static fn (string $json): mixed => $emptyObjects
            ? EmptyObject::decode($json, 512, JSON_THROW_ON_ERROR)
            : json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        try {
            $parsedValue = $decode($value);

            /*
             * On Strapi 5 until 5.0.0-rc.7, the values were accidentally stringified twice when saved,
             * so in those cases we need to parse them twice to retrieve the actual value.
             */
            if (is_string($parsedValue)) {
                return $decode($parsedValue);
            }

            return $parsedValue;
        } catch (\JsonException) {
            // Just return the value if it's not a valid JSON string
            return $value;
        }
    }
}
