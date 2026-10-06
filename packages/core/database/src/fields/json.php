<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

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
        if (!is_string($value)) {
            return $value;
        }

        try {
            $parsedValue = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            /*
             * On Strapi 5 until 5.0.0-rc.7, the values were accidentally stringified twice when saved,
             * so in those cases we need to parse them twice to retrieve the actual value.
             */
            if (is_string($parsedValue)) {
                return json_decode($parsedValue, true, 512, JSON_THROW_ON_ERROR);
            }

            return $parsedValue;
        } catch (\JsonException) {
            // Just return the value if it's not a valid JSON string
            return $value;
        }
    }
}
