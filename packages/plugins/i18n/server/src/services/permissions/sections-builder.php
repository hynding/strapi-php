<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services\Permissions;

use Strapi\Admin\Services\Permission;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;

/** Port of server/src/services/permissions/sections-builder.ts. */
final class SectionsBuilder
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Handler for the permissions layout (sections builder)
     * Adds the locales property to the subjects
     *
     * The section builder passes `['action' => $action, 'section' => &$section]`.
     *
     * @param array<string, mixed> $ctx
     */
    public function localesPropertyHandler(array $ctx): void
    {
        $permission = $this->strapi->service('admin::permission');
        assert($permission instanceof Permission);
        $actionProvider = $permission->actionProvider;

        $localesService = Utils::locales($this->strapi);
        $locales = $localesService->setIsDefault($localesService->find());

        // Do not add the locales property if there is none registered
        if ($locales === null || $locales === []) {
            return;
        }

        $action = is_array($ctx['action'] ?? null) ? $ctx['action'] : [];
        $subjects = is_array($ctx['section']['subjects'] ?? null) ? $ctx['section']['subjects'] : [];

        foreach ($subjects as $index => $subject) {
            $applies = $actionProvider->appliesToProperty('locales', (string) ($action['actionId'] ?? ''), (string) ($subject['uid'] ?? ''));
            $hasLocalesProperty = false;
            foreach (is_array($subject['properties'] ?? null) ? $subject['properties'] : [] as $property) {
                if (is_array($property) && ($property['value'] ?? null) === 'locales') {
                    $hasLocalesProperty = true;
                    break;
                }
            }

            if ($applies && !$hasLocalesProperty) {
                $ctx['section']['subjects'][$index]['properties'][] = [
                    'label' => 'Locales',
                    'value' => 'locales',
                    'children' => array_map(static fn (array $locale): array => [
                        'label' => ($locale['name'] ?? null) ?: ($locale['code'] ?? null),
                        'value' => $locale['code'] ?? null,
                        'isDefault' => $locale['isDefault'] ?? false,
                    ], array_values($locales)),
                ];
            }
        }
    }

    public function registerLocalesPropertyHandler(): void
    {
        $permission = $this->strapi->service('admin::permission');
        assert($permission instanceof Permission);
        $sectionsBuilder = $permission->sectionsBuilder;

        $sectionsBuilder->addHandler('singleTypes', $this->localesPropertyHandler(...));
        $sectionsBuilder->addHandler('collectionTypes', $this->localesPropertyHandler(...));
    }
}
