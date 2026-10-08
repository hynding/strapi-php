<?php

declare(strict_types=1);

namespace Strapi\Core\Domain\ContentType;

use Strapi\Utils\Errors\YupValidationError;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of packages/core/core/src/domain/content-type/validator.ts (yup schema expressed by hand).
 */
final class Validator
{
    public const LIFECYCLES = [
        'beforeCreate', 'afterCreate', 'beforeFindOne', 'afterFindOne', 'beforeFindMany', 'afterFindMany',
        'beforeCount', 'afterCount', 'beforeCreateMany', 'afterCreateMany', 'beforeUpdate', 'afterUpdate',
        'beforeUpdateMany', 'afterUpdateMany', 'beforeDelete', 'afterDelete', 'beforeDeleteMany', 'afterDeleteMany',
    ];

    /**
     * For enumerations the least common denominator is GraphQL, where values need to match the
     * secure name regex: https://spec.graphql.org/June2018/#sec-Names
     */
    private const GRAPHQL_ENUM_REGEX = '/^[_A-Za-z][_0-9A-Za-z]*$/';

    /**
     * @param array{schema?: array<string, mixed>, actions?: mixed, lifecycles?: mixed} $data
     * @throws YupValidationError
     */
    public static function validateContentTypeDefinition(array $data): void
    {
        $errors = [];
        $schema = $data['schema'] ?? null;

        if (!is_array($schema)) {
            $errors[] = self::error(['schema'], 'schema is a required field', $schema);
        } else {
            $info = $schema['info'] ?? null;
            if (!is_array($info)) {
                $errors[] = self::error(['schema', 'info'], 'schema.info is a required field', $info);
            } else {
                foreach (['displayName', 'singularName', 'pluralName'] as $field) {
                    $value = $info[$field] ?? null;
                    if (!is_string($value) || $value === '') {
                        $errors[] = self::error(['schema', 'info', $field], "schema.info.{$field} is a required field", $value);
                    } elseif ($field !== 'displayName' && !Strings::isKebabCase($value)) {
                        $errors[] = self::error(['schema', 'info', $field], "schema.info.{$field} is not in kebab case (an-example-of-kebab-case)", $value);
                    }
                }
            }

            $attributes = $schema['attributes'] ?? [];
            if (is_array($attributes)) {
                $message = self::checkEnumerations($attributes);
                if ($message !== null) {
                    $errors[] = self::error(['schema', 'attributes'], $message, $attributes);
                }
            }
        }

        $actions = $data['actions'] ?? [];
        if (is_array($actions)) {
            foreach ($actions as $value) {
                if (!is_callable($value)) {
                    $errors[] = self::error(['actions'], 'actions contains values that are not functions', $actions);
                    break;
                }
            }
        }

        $lifecycles = $data['lifecycles'] ?? [];
        if (is_array($lifecycles)) {
            foreach ($lifecycles as $name => $value) {
                if (!in_array((string) $name, self::LIFECYCLES, true)) {
                    $errors[] = self::error(['lifecycles'], "lifecycles field has unspecified keys: {$name}", $lifecycles);
                } elseif ($value !== null && !is_callable($value)) {
                    $errors[] = self::error(['lifecycles', (string) $name], "lifecycles.{$name} is not a function", $value);
                }
            }
        }

        if ($errors !== []) {
            throw new YupValidationError($errors);
        }
    }

    /** @param array<string, mixed> $attributes */
    private static function checkEnumerations(array $attributes): ?string
    {
        foreach ($attributes as $attrName => $attr) {
            if (!is_array($attr) || ($attr['type'] ?? null) !== 'enumeration') {
                continue;
            }

            $values = is_array($attr['enum'] ?? null) ? $attr['enum'] : [];
            $regressedValues = array_map(static fn (mixed $v): string => Strings::toRegressedEnumValue((string) $v), $values);

            foreach ($regressedValues as $value) {
                if (preg_match(self::GRAPHQL_ENUM_REGEX, $value) !== 1) {
                    return "Invalid enumeration value. Values should have at least one alphabetical character preceding the first occurence of a number. Update your enumeration '{$attrName}'.";
                }
            }

            // An empty regressed value never matches GRAPHQL_ENUM_REGEX, so the loop above already
            // reports it (same message upstream's yup test ends up producing).

            $counts = array_count_values($regressedValues);
            $duplicates = array_keys(array_filter($counts, static fn (int $c): bool => $c > 1));
            if ($duplicates !== []) {
                return "Some enumeration values of the field '{$attrName}' collide when normalized: " . implode(', ', $duplicates) . '. Please modify your enumeration.';
            }
        }

        return null;
    }

    /**
     * @param list<string> $path
     * @return array{path: list<string>, message: string, name: string, value: mixed}
     */
    private static function error(array $path, string $message, mixed $value): array
    {
        return ['path' => $path, 'message' => $message, 'name' => 'ValidationError', 'value' => $value];
    }
}
