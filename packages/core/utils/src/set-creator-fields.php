<?php

declare(strict_types=1);

namespace Strapi\Utils;

/** Port of packages/core/utils/src/set-creator-fields.ts. */
final class SetCreatorFields
{
    /**
     * @param array{user: array{id: int|string}|object, isEdition?: bool} $options
     * @return callable(array<string, mixed>): array<string, mixed>
     */
    public static function create(array $options): callable
    {
        $user = $options['user'];
        $isEdition = $options['isEdition'] ?? false;
        $id = is_array($user) ? $user['id'] : (property_exists($user, 'id') ? $user->id : null);

        return static function (array $data) use ($id, $isEdition): array {
            if ($isEdition) {
                return [...$data, ContentTypes::UPDATED_BY_ATTRIBUTE => $id];
            }

            return [
                ...$data,
                ContentTypes::CREATED_BY_ATTRIBUTE => $id,
                ContentTypes::UPDATED_BY_ATTRIBUTE => $id,
            ];
        };
    }

    /**
     * Shorthand: `SetCreatorFields::apply($data, ['user' => $user, 'isEdition' => true])`.
     *
     * @param array<string, mixed> $data
     * @param array{user: array{id: int|string}|object, isEdition?: bool} $options
     * @return array<string, mixed>
     */
    public static function apply(array $data, array $options): array
    {
        return self::create($options)($data);
    }
}
