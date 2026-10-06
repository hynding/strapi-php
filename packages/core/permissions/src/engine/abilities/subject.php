<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Abilities;

/**
 * CASL's `subject(type, object)` helper: tags an entity with its subject type so that
 * `ability.can('read', subject('article', $entity))` can evaluate conditions against it.
 *
 * `Subject::detectSubjectType()` mirrors `detectSubjectType`: a string is its own type, an
 * array or object carrying `__caslSubjectType__` (or `uid` / `__type`) is typed by that.
 */
final class Subject
{
    public const TYPE_KEY = '__caslSubjectType__';

    /**
     * @param array<string, mixed>|object $object
     * @return array<string, mixed>|object
     */
    public static function subject(string $type, array|object $object): array|object
    {
        if (is_array($object)) {
            return [self::TYPE_KEY => $type, ...$object];
        }
        if ($object instanceof \stdClass) {
            $object->{self::TYPE_KEY} = $type;

            return $object;
        }

        return new TypedSubject($type, $object);
    }

    public static function detectSubjectType(mixed $subject): ?string
    {
        if (is_string($subject)) {
            return $subject;
        }
        if ($subject instanceof TypedSubject) {
            return $subject->type;
        }
        if (is_array($subject)) {
            foreach ([self::TYPE_KEY, 'uid', '__type'] as $key) {
                if (isset($subject[$key]) && is_string($subject[$key])) {
                    return $subject[$key];
                }
            }

            return null;
        }
        if (is_object($subject)) {
            foreach ([self::TYPE_KEY, 'uid', '__type'] as $key) {
                if (isset($subject->{$key}) && is_string($subject->{$key})) {
                    return $subject->{$key};
                }
            }
            if (method_exists($subject, 'getSubjectType')) {
                $type = $subject->getSubjectType();

                return is_string($type) ? $type : null;
            }

            return null;
        }

        return null;
    }

    /** The entity to match conditions against (unwrapping a TypedSubject). */
    public static function entity(mixed $subject): mixed
    {
        return $subject instanceof TypedSubject ? $subject->object : $subject;
    }
}
