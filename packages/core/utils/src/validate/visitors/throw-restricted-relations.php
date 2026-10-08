<?php

declare(strict_types=1);

namespace Strapi\Utils\Validate\Visitors;

use Strapi\Utils\AuthScope;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Relations;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\Validate\Utils;

/** Throws for relations whose target the current `$auth` may not `find`. See {@see AuthScope}. */
final class ThrowRestrictedRelations
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
        $path = $options->path->attribute;
        $data = is_array($options->data) ? $options->data : [];
        $isCreatorRelation = in_array($key, [ContentTypes::CREATED_BY_ATTRIBUTE, ContentTypes::UPDATED_BY_ATTRIBUTE], true);

        // Polymorphic relations
        if (ContentTypes::isMorphToRelationalAttribute($attribute)) {
            $value = $data[$key] ?? null;
            if (self::isMorphPopulateAllOrCount($value)) {
                return;
            }

            $this->handleMorphRelation($key, $path, $value);

            return;
        }

        // Creator relations
        if ($isCreatorRelation && (ContentTypes::options($options->schema)['populateCreatorFields'] ?? false)) {
            return;
        }

        // Regular relations
        $scopes = array_map(static fn (string $action): string => ($attribute['target'] ?? '') . ".{$action}", self::ACTIONS_TO_VERIFY);
        if (!AuthScope::hasAccessToSomeScopes($scopes, $this->auth)) {
            Utils::throwInvalidKey(['key' => $key, 'path' => $path]);
        }
    }

    private function handleMorphRelation(string $key, ?string $path, mixed $elements): void
    {
        if (self::isMorphMutationPayload($elements)) {
            /** @var array<string, mixed> $elements */
            $this->handleMorphElements($key, $path, $elements['connect'] ?? []);
            $this->handleMorphElements($key, $path, $elements['set'] ?? []);
            $this->handleMorphElements($key, $path, $elements['disconnect'] ?? []);

            if (array_key_exists('options', $elements)) {
                $opts = $elements['options'];
                if ($opts === null) {
                    return;
                }
                if (!is_array($opts)) {
                    Utils::throwInvalidKey(['key' => $key, 'path' => $path]);
                }

                $validators = Relations::validRelationOrderingKeys();
                foreach ($opts as $optionKey => $optionValue) {
                    $validator = $validators[$optionKey] ?? null;
                    if ($validator === null || !$validator($optionValue)) {
                        Utils::throwInvalidKey(['key' => (string) $optionKey, 'path' => $path]);
                    }
                }
            }
        } elseif (is_array($elements) && self::isMorphPopulatePayload($elements) && is_array($elements['on'])) {
            foreach (array_keys($elements['on']) as $uid) {
                $scopes = array_map(static fn (string $action): string => "{$uid}.{$action}", self::ACTIONS_TO_VERIFY);
                if (!AuthScope::hasAccessToSomeScopes($scopes, $this->auth)) {
                    Utils::throwInvalidKey(['key' => $key, 'path' => $path]);
                }
            }
        } else {
            $this->handleMorphElements($key, $path, $elements);
        }
    }

    private static function isMorphMutationPayload(mixed $value): bool
    {
        return is_array($value) && (array_key_exists('connect', $value) || array_key_exists('set', $value) || array_key_exists('disconnect', $value) || array_key_exists('options', $value));
    }

    private static function isMorphPopulatePayload(mixed $value): bool
    {
        return is_array($value) && !array_key_exists('__type', $value) && array_key_exists('on', $value) && is_array($value['on']);
    }

    private static function isMorphPopulateAllOrCount(mixed $value): bool
    {
        return $value === true || (is_array($value) && array_key_exists('count', $value) && $value['count'] === true);
    }

    private function handleMorphElements(string $key, ?string $path, mixed $elements): void
    {
        if (!is_array($elements) || !array_is_list($elements)) {
            Utils::throwInvalidKey(['key' => $key, 'path' => $path]);
        }

        foreach ($elements as $element) {
            if (!is_array($element) || !isset($element['__type']) || !is_string($element['__type'])) {
                Utils::throwInvalidKey(['key' => $key, 'path' => $path]);
            }

            $scopes = array_map(static fn (string $action): string => "{$element['__type']}.{$action}", self::ACTIONS_TO_VERIFY);
            if (!AuthScope::hasAccessToSomeScopes($scopes, $this->auth)) {
                Utils::throwInvalidKey(['key' => $key, 'path' => $path]);
            }
        }
    }
}
