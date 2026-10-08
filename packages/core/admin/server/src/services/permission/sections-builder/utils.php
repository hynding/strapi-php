<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission\SectionsBuilder;

use Strapi\Core\Core;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/permission/sections-builder/utils.ts. */
final class Utils
{
    /** @return \Closure(mixed): bool */
    public static function isOfKind(mixed $kind): \Closure
    {
        return static function (mixed $ct) use ($kind): bool {
            if ($ct instanceof Schema) {
                return $ct->kind === $kind;
            }

            return is_array($ct) && ($ct['kind'] ?? null) === $kind;
        };
    }

    /** `strapi.contentTypes[uid]` */
    public static function resolveContentType(string $uid): ?Schema
    {
        $contentTypes = Core::instance()?->contentTypes() ?? [];

        return $contentTypes[$uid] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $subjects
     * @return \Closure(mixed): bool
     */
    public static function isNotInSubjects(array $subjects): \Closure
    {
        return static function (mixed $uid) use ($subjects): bool {
            foreach ($subjects as $subject) {
                if (($subject['uid'] ?? null) === $uid) {
                    return false;
                }
            }

            return true;
        };
    }

    /** @param array<string, mixed> $subject */
    public static function hasProperty(string $property, array $subject): bool
    {
        foreach ($subject['properties'] ?? [] as $prop) {
            if (($prop['value'] ?? null) === $property) {
                return true;
            }
        }

        return false;
    }

    /**
     * `pick(['applyToProperties'])`
     *
     * @param array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public static function getValidOptions(?array $options): array
    {
        return is_array($options) && array_key_exists('applyToProperties', $options)
            ? ['applyToProperties' => $options['applyToProperties']]
            : [];
    }

    /**
     * @param Schema|array<string, mixed> $ct
     * @return array{uid: string, label: mixed, properties: list<array<string, mixed>>}
     */
    public static function toSubjectTemplate(Schema|array $ct): array
    {
        $uid = $ct instanceof Schema ? $ct->uid : (string) ($ct['uid'] ?? '');
        $info = $ct instanceof Schema ? $ct->info : ($ct['info'] ?? []);

        return [
            'uid' => $uid,
            'label' => $info['singularName'] ?? null,
            'properties' => [],
        ];
    }
}
