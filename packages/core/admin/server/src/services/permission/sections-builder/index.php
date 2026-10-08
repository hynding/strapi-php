<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission\SectionsBuilder;

/**
 * Port of server/src/services/permission/sections-builder/index.ts
 * (`createDefaultSectionBuilder()`).
 */
final class SectionsBuilder
{
    /** @return \Closure(array<string, mixed>): bool */
    private static function sectionPropMatcher(string $section): \Closure
    {
        return static fn (array $action): bool => ($action['section'] ?? null) === $section;
    }

    /** @return array{actions: list<array<string, mixed>>, subjects: list<array<string, mixed>>} */
    public static function createContentTypesInitialState(): array
    {
        return ['actions' => [], 'subjects' => []];
    }

    public static function createDefaultSectionBuilder(): Builder
    {
        $builder = Builder::createSectionBuilder();

        $builder->createSection('plugins', [
            'initialStateFactory' => static fn (): array => [],
            'handlers' => [Handlers::plugins(...)],
            'matchers' => [self::sectionPropMatcher('plugins')],
        ]);

        $builder->createSection('settings', [
            'initialStateFactory' => static fn (): array => [],
            'handlers' => [Handlers::settings(...)],
            'matchers' => [self::sectionPropMatcher('settings')],
        ]);

        $builder->createSection('singleTypes', [
            'initialStateFactory' => self::createContentTypesInitialState(...),
            'handlers' => [Handlers::contentTypesBase(...), Handlers::subjectsHandlerFor('singleType'), Handlers::fieldsProperty(...)],
            'matchers' => [self::sectionPropMatcher('contentTypes')],
        ]);

        $builder->createSection('collectionTypes', [
            'initialStateFactory' => self::createContentTypesInitialState(...),
            'handlers' => [Handlers::contentTypesBase(...), Handlers::subjectsHandlerFor('collectionType'), Handlers::fieldsProperty(...)],
            'matchers' => [self::sectionPropMatcher('contentTypes')],
        ]);

        return $builder;
    }
}
