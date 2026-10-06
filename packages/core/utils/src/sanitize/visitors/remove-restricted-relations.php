<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize\Visitors;

use Strapi\Utils\AuthScope;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Relations;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/**
 * Removes relations whose target the current `$auth` may not `find`. Upstream exports a factory:
 * `removeRestrictedRelations(auth)` → visitor. Scope decisions go through {@see AuthScope}.
 */
final class RemoveRestrictedRelations
{
    private const ACTIONS_TO_VERIFY = ['find'];

    public function __construct(private readonly mixed $auth)
    {
    }

    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        $attribute = $options->attribute;

        if ($attribute === null || ($attribute['type'] ?? null) !== 'relation') {
            return;
        }

        $key = $options->key;
        $data = is_array($options->data) ? $options->data : [];
        $value = $data[$key] ?? null;

        $isCreatorRelation = in_array($key, [ContentTypes::CREATED_BY_ATTRIBUTE, ContentTypes::UPDATED_BY_ATTRIBUTE], true);

        if (ContentTypes::isMorphToRelationalAttribute($attribute)) {
            if (self::isMorphPopulatePayload($value)) {
                $this->handleMorphRelation($key, $value, $utils);

                return;
            }

            if (self::isMorphPopulateAllOrCount($value)) {
                $newOn = $this->buildAllowedMorphPopulateFragment(true);

                if ($newOn === []) {
                    $utils->remove($key);

                    return;
                }

                $utils->set(
                    $key,
                    $value === true || $value === 'true'
                        ? ['on' => $newOn]
                        : [...(is_array($value) ? $value : []), 'count' => true, 'on' => $newOn],
                );

                return;
            }

            if (self::isMorphCountOutput($value)) {
                return;
            }

            $this->handleMorphRelation($key, $value, $utils);

            return;
        }

        if ($isCreatorRelation && (ContentTypes::options($options->schema)['populateCreatorFields'] ?? false)) {
            return;
        }

        // Regular relation
        $scopes = array_map(static fn (string $action): string => ($attribute['target'] ?? '') . ".{$action}", self::ACTIONS_TO_VERIFY);
        if (!AuthScope::hasAccessToSomeScopes($scopes, $this->auth)) {
            $utils->remove($key);
        }
    }

    private function handleMorphRelation(string $key, mixed $elements, VisitorUtils $utils): void
    {
        if ($elements === null) {
            return;
        }

        if (self::isMorphMutationPayload($elements)) {
            /** @var array<string, mixed> $elements */
            $newValue = [];

            $connect = $this->handleMorphElements($elements['connect'] ?? []);
            $relSet = $this->handleMorphElements($elements['set'] ?? []);
            $disconnect = $this->handleMorphElements($elements['disconnect'] ?? []);

            if ($connect !== []) {
                $newValue['connect'] = $connect;
            }
            if ($relSet !== []) {
                $newValue['set'] = $relSet;
            }
            if ($disconnect !== []) {
                $newValue['disconnect'] = $disconnect;
            }

            $options = $elements['options'] ?? null;
            if (is_array($options)) {
                $filteredOptions = [];
                $validators = Relations::validRelationOrderingKeys();
                foreach ($options as $optionKey => $optionValue) {
                    $validator = $validators[$optionKey] ?? null;
                    if ($validator !== null && $validator($optionValue)) {
                        $filteredOptions[$optionKey] = $optionValue;
                    }
                }
                $newValue['options'] = $filteredOptions;
            } else {
                $newValue['options'] = [];
            }

            $utils->set($key, $newValue);
        } elseif (self::isMorphPopulatePayload($elements)) {
            /** @var array{on: array<string, mixed>, count?: mixed} $elements */
            $newOn = [];

            foreach ($elements['on'] as $uid => $subPopulate) {
                $scopes = array_map(static fn (string $action): string => "{$uid}.{$action}", self::ACTIONS_TO_VERIFY);
                if (AuthScope::hasAccessToSomeScopes($scopes, $this->auth)) {
                    $newOn[$uid] = $subPopulate;
                }
            }

            if ($newOn === []) {
                $utils->remove($key);

                return;
            }

            $newElements = [...$elements, 'on' => $newOn];
            if (array_key_exists('count', $elements)) {
                $newElements['count'] = self::isMorphPopulateCount($elements['count']) ? true : $elements['count'];
            }
            $utils->set($key, $newElements);
        } else {
            $newMorphValue = $this->handleMorphElements($elements);

            if ($newMorphValue === []) {
                if (is_array($elements) && array_is_list($elements) && $elements === []) {
                    return;
                }

                $utils->remove($key);

                return;
            }

            if (is_array($elements) && array_is_list($elements)) {
                $utils->set($key, $newMorphValue);

                return;
            }

            $utils->set($key, $newMorphValue[0]);
        }
    }

    private static function isMorphMutationPayload(mixed $value): bool
    {
        return is_array($value) && (array_key_exists('connect', $value) || array_key_exists('set', $value) || array_key_exists('disconnect', $value));
    }

    private static function isMorphPopulateCount(mixed $value): bool
    {
        return $value === true || $value === 'true';
    }

    private static function isMorphPopulatePayload(mixed $value): bool
    {
        return is_array($value) && !array_key_exists('__type', $value) && array_key_exists('on', $value) && is_array($value['on']);
    }

    private static function isMorphPopulateAllOrCount(mixed $value): bool
    {
        return $value === true
            || $value === 'true'
            || (is_array($value) && array_key_exists('count', $value) && self::isMorphPopulateCount($value['count']) && !array_key_exists('on', $value));
    }

    private static function isMorphCountOutput(mixed $value): bool
    {
        return is_array($value) && array_key_exists('count', $value) && is_int($value['count']) && count($value) === 1;
    }

    /** @return array<string, true> */
    private function buildAllowedMorphPopulateFragment(mixed $subPopulate): array
    {
        $newOn = [];

        foreach (AuthScope::getRegisteredContentTypeUIDs() as $uid) {
            $scopes = array_map(static fn (string $action): string => "{$uid}.{$action}", self::ACTIONS_TO_VERIFY);
            if (AuthScope::hasAccessToSomeScopes($scopes, $this->auth)) {
                $newOn[$uid] = $subPopulate;
            }
        }

        return $newOn;
    }

    /** @return list<array<string, mixed>> */
    private function handleMorphElements(mixed $elements): array
    {
        $allowedElements = [];
        $elementsToCheck = is_array($elements) && array_is_list($elements) ? $elements : [$elements];

        foreach ($elementsToCheck as $element) {
            if (!is_array($element) || !isset($element['__type']) || !is_string($element['__type'])) {
                continue;
            }

            $scopes = array_map(static fn (string $action): string => "{$element['__type']}.{$action}", self::ACTIONS_TO_VERIFY);
            if (AuthScope::hasAccessToSomeScopes($scopes, $this->auth)) {
                $allowedElements[] = $element;
            }
        }

        return $allowedElements;
    }
}
