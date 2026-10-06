<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes;

/**
 * Port of packages/core/core/src/services/entity-validator/validators.ts: per-type validators.
 * Drafts have limited validations (mainly max constraints); published content undergoes full
 * validation. Unique fields are unique within the same locale and publication state.
 *
 * `$metas` = `['attr' => attribute, 'model' => Schema|array, 'updatedAttribute' => ['name', 'value'], 'data' => [...], 'componentContext' => ?, 'entity' => ?]`,
 * `$options` = `['isDraft' => bool, 'locale' => ?string]`.
 *
 * @phpstan-type ValidatorMetas array{attr: array<string, mixed>, model: \Strapi\Types\Schema\Schema|array<string, mixed>, updatedAttribute: array{name: string, value: mixed}, data?: array<string, mixed>, componentContext?: array<string, mixed>|null, entity?: array<string, mixed>|null}
 * @phpstan-type ValidatorOptions array{isDraft: bool, locale?: string|null}
 */
final class Validators
{
    private const BIG_INTEGER_REGEX = '/^[+-]?\d+$/';

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /* Validator utils */

    private static function toNumberSafe(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }
        if (is_numeric($value)) {
            $num = $value + 0;

            return is_float($num) && !is_finite($num) ? null : $num;
        }

        return null;
    }

    public static function toBigIntegerString(mixed $value): ?string
    {
        if ($value === null || $value instanceof Undefined) {
            return null;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value) || floor($value) !== $value) {
                return null;
            }

            return number_format($value, 0, '', '');
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match(self::BIG_INTEGER_REGEX, $trimmed) !== 1) {
                return null;
            }
            $normalized = ltrim($trimmed, '+');
            $negative = str_starts_with($normalized, '-');
            $digits = ltrim(ltrim($normalized, '-'), '0');
            $digits = $digits === '' ? '0' : $digits;

            return ($negative && $digits !== '0' ? '-' : '') . $digits;
        }

        return null;
    }

    public static function isValidBigInteger(mixed $value): bool
    {
        return self::toBigIntegerString($value) !== null;
    }

    public static function isValidFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite($value);
    }

    public static function isValidInteger(mixed $value): bool
    {
        return self::isValidFiniteNumber($value) && (is_int($value) || floor($value) === $value);
    }

    private static function shouldSkipUniqueValidation(string $attrType, mixed $value): bool
    {
        return match ($attrType) {
            'integer' => !self::isValidInteger($value),
            'float', 'decimal' => !self::isValidFiniteNumber($value),
            'biginteger' => !self::isValidBigInteger($value),
            default => false,
        };
    }

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    private static function addMinLengthValidator(YupString $validator, array $metas, array $options): YupString
    {
        $attr = $metas['attr'];

        return isset($attr['minLength']) && is_int($attr['minLength']) && $attr['minLength'] && !$options['isDraft']
            ? $validator->min($attr['minLength'])
            : $validator;
    }

    /** @param ValidatorMetas $metas */
    private static function addMaxLengthValidator(YupString $validator, array $metas): YupString
    {
        $attr = $metas['attr'];

        // apply default max length for short text (string)
        if (($attr['type'] ?? null) === 'string') {
            $max = isset($attr['maxLength']) && is_int($attr['maxLength']) ? $attr['maxLength'] : 255;

            return $validator->max($max);
        }

        // keep existing behavior for other types
        return isset($attr['maxLength']) && is_int($attr['maxLength']) && $attr['maxLength'] ? $validator->max($attr['maxLength']) : $validator;
    }

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    private static function addMinIntegerValidator(YupNumber $validator, array $metas, array $options): YupNumber
    {
        $min = self::toNumberSafe($metas['attr']['min'] ?? null);

        return $min !== null && !$options['isDraft'] ? $validator->min((int) $min) : $validator;
    }

    /** @param ValidatorMetas $metas */
    private static function addMaxIntegerValidator(YupNumber $validator, array $metas): YupNumber
    {
        $max = self::toNumberSafe($metas['attr']['max'] ?? null);

        return $max !== null ? $validator->max((int) $max) : $validator;
    }

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    private static function addMinFloatValidator(YupNumber $validator, array $metas, array $options): YupNumber
    {
        $min = self::toNumberSafe($metas['attr']['min'] ?? null);

        return $min !== null && !$options['isDraft'] ? $validator->min($min) : $validator;
    }

    /** @param ValidatorMetas $metas */
    private static function addMaxFloatValidator(YupNumber $validator, array $metas): YupNumber
    {
        $max = self::toNumberSafe($metas['attr']['max'] ?? null);

        return $max !== null ? $validator->max($max) : $validator;
    }

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    private static function addStringRegexValidator(YupString $validator, array $metas, array $options): YupString
    {
        $attr = $metas['attr'];

        return isset($attr['regex']) && !$options['isDraft']
            ? $validator->matches((string) $attr['regex'], ['excludeEmptyString' => !($attr['required'] ?? false)])
            : $validator;
    }

    /**
     * @template T of Yup
     * @param T $validator
     * @param ValidatorMetas $metas
     * @param ValidatorOptions $options
     * @return T
     */
    private function addUniqueValidator(Yup $validator, array $metas, array $options): Yup
    {
        $attr = $metas['attr'];
        $model = $metas['model'];
        $updatedAttribute = $metas['updatedAttribute'];
        $entity = $metas['entity'] ?? null;
        $componentContext = $metas['componentContext'] ?? null;

        if (($attr['type'] ?? null) !== 'uid' && !($attr['unique'] ?? false)) {
            return $validator;
        }

        $validateUniqueFieldWithinComponent = function (mixed $value) use ($componentContext, $updatedAttribute): bool {
            if ($componentContext === null) {
                return false;
            }

            // If we are validating a unique field within a repeatable component,
            // we first need to ensure that the repeatable in the current entity is valid against itself.
            $repeatableData = $componentContext['repeatableData'] ?? [];
            if ($repeatableData !== []) {
                $pathToCheck = [...array_slice($componentContext['pathToComponent'], 1), $updatedAttribute['name']];
                $values = array_map(static function (mixed $item) use ($pathToCheck): mixed {
                    $acc = $item;
                    foreach ($pathToCheck as $key) {
                        $acc = is_array($acc) ? ($acc[$key] ?? null) : null;
                    }

                    return $acc;
                }, $repeatableData);

                $count = count(array_filter($values, static fn (mixed $v): bool => $v === $updatedAttribute['value']));
                if ($count > 1) {
                    return false;
                }
            }

            $parentContent = $componentContext['parentContent'];
            $parentModel = $parentContent['model'];
            $parentOptions = $parentContent['options'] ?? null;
            $excludeId = $parentContent['id'] ?? null;

            $whereConditions = [];
            $isParentDraft = $parentOptions !== null && ($parentOptions['isDraft'] ?? false);

            $whereConditions['publishedAt'] = $isParentDraft ? null : ['$notNull' => true];

            if (!empty($parentOptions['locale'])) {
                $whereConditions['locale'] = $parentOptions['locale'];
            }

            if ($excludeId !== null && $excludeId !== '') {
                $whereConditions['id'] = ['$ne' => $excludeId];
            }

            $queryUid = ContentTypes::uid($parentModel) ?? '';
            $nested = [$updatedAttribute['name'] => $value];
            foreach (array_reverse($componentContext['pathToComponent']) as $key) {
                $nested = [$key => $nested];
            }
            $queryWhere = [...$nested, ...$whereConditions];

            // The validation should pass if there is no other record found from the query
            return $this->strapi->db()->query($queryUid)->findOne(['where' => $queryWhere]) === null;
        };

        $validateUniqueFieldWithinDynamicZoneComponent = function (string $startOfPath) use ($componentContext, $updatedAttribute, $model): bool {
            if ($componentContext === null) {
                return false;
            }

            $targetComponentUID = ContentTypes::uid($model);
            // Ensure that the value is unique within the dynamic zone in this entity.
            $countOfValueInThisEntity = 0;
            foreach ($componentContext['fullDynamicZoneContent'] ?? [] as $component) {
                if (($component['__component'] ?? null) !== $targetComponentUID) {
                    continue;
                }
                if (($component[$updatedAttribute['name']] ?? null) === $updatedAttribute['value']) {
                    $countOfValueInThisEntity++;
                }
            }

            if ($countOfValueInThisEntity > 1) {
                return false;
            }

            $query = [
                'select' => ['id'],
                'where' => [],
                'populate' => [
                    $startOfPath => [
                        'on' => [
                            $targetComponentUID => ['select' => ['id'], 'where' => [$updatedAttribute['name'] => $updatedAttribute['value']]],
                        ],
                    ],
                ],
            ];

            $parentContent = $componentContext['parentContent'];
            $options = $parentContent['options'] ?? null;
            $id = $parentContent['id'] ?? null;

            if ($options !== null && array_key_exists('isDraft', $options)) {
                $query['where']['publishedAt'] = $options['isDraft'] ? ['$null' => true] : ['$notNull' => true];
            }
            if ($id !== null && $id !== '') {
                $query['where']['id'] = ['$ne' => $id];
            }
            if (!empty($options['locale'])) {
                $query['where']['locale'] = $options['locale'];
            }

            $results = $this->strapi->db()->query(ContentTypes::uid($parentContent['model']) ?? '')->findMany($query);

            foreach ($results as $result) {
                $zone = $result[$startOfPath] ?? null;
                if (!is_array($zone) || $zone === []) {
                    continue;
                }
                foreach ($zone as $component) {
                    if (($component['__component'] ?? null) === $targetComponentUID) {
                        return false;
                    }
                }
            }

            return true;
        };

        return $validator->test('unique', 'This attribute must be unique', function (mixed $value) use ($attr, $model, $updatedAttribute, $entity, $componentContext, $options, $validateUniqueFieldWithinComponent, $validateUniqueFieldWithinDynamicZoneComponent): bool {
            // If the attribute value is `null` or an empty string we want to skip the unique validation.
            if ($value === null || $value === '' || $value instanceof Undefined) {
                return true;
            }

            // Skip unique checks for invalid scalar numeric values and let the type validator fail.
            if (self::shouldSkipUniqueValidation((string) ($attr['type'] ?? ''), $value)) {
                return true;
            }

            // We don't validate any unique constraint for draft entries.
            if ($options['isDraft']) {
                return true;
            }

            $hasPathToComponent = $componentContext !== null && ($componentContext['pathToComponent'] ?? []) !== [];
            if ($hasPathToComponent) {
                $startOfPath = $componentContext['pathToComponent'][0];
                $parentAttributes = ContentTypes::attributes($componentContext['parentContent']['model']);
                $testingDZ = ($parentAttributes[$startOfPath]['type'] ?? null) === 'dynamiczone';

                if ($testingDZ) {
                    return $validateUniqueFieldWithinDynamicZoneComponent($startOfPath);
                }

                return $validateUniqueFieldWithinComponent($value);
            }

            $scalarAttributeWhere = [$updatedAttribute['name'] => $value, 'publishedAt' => ['$notNull' => true]];

            if (!empty($options['locale'])) {
                $scalarAttributeWhere['locale'] = $options['locale'];
            }

            if (!empty($entity['id'])) {
                $scalarAttributeWhere['id'] = ['$ne' => $entity['id']];
            }

            // The validation should pass if there is no other record found from the query
            return $this->strapi->db()->query(ContentTypes::uid($model) ?? '')->findOne(['where' => $scalarAttributeWhere, 'select' => ['id']]) === null;
        });
    }

    /* Type validators */

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    public function string(array $metas, array $options): YupString
    {
        $schema = Yup::string()->transform(static fn (mixed $val, mixed $originalVal): mixed => $originalVal);

        $schema = self::addMinLengthValidator($schema, $metas, $options);
        $schema = self::addMaxLengthValidator($schema, $metas);
        $schema = self::addStringRegexValidator($schema, $metas, $options);

        return $this->addUniqueValidator($schema, $metas, $options);
    }

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    public function email(array $metas, array $options): YupString
    {
        $schema = $this->string($metas, $options);

        if ($options['isDraft']) {
            return $schema;
        }

        return $schema->email()->min(1, '${path} cannot be empty');
    }

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    public function uid(array $metas, array $options): YupString
    {
        $schema = $this->string($metas, $options);

        if ($options['isDraft']) {
            return $schema;
        }

        if (!empty($metas['attr']['regex'])) {
            return $schema->matches((string) $metas['attr']['regex']);
        }

        return $schema->matches('^[A-Za-z0-9-_.~]*$');
    }

    /** @param ValidatorMetas $metas */
    public function enumeration(array $metas): YupString
    {
        $values = $metas['attr']['enum'] ?? [];
        $values = is_array($values) ? array_values($values) : [$values];

        return Yup::string()->oneOf([...$values, null]);
    }

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    public function integer(array $metas, array $options): YupNumber
    {
        $schema = Yup::number()->integer();

        $schema = self::addMinIntegerValidator($schema, $metas, $options);
        $schema = self::addMaxIntegerValidator($schema, $metas);

        return $this->addUniqueValidator($schema, $metas, $options);
    }

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    public function float(array $metas, array $options): YupNumber
    {
        $schema = Yup::number()->test('is-finite-number', '${path} must be a finite number', static fn (mixed $value): bool => $value === null || $value instanceof Undefined || self::isValidFiniteNumber($value));

        $schema = self::addMinFloatValidator($schema, $metas, $options);
        $schema = self::addMaxFloatValidator($schema, $metas);

        return $this->addUniqueValidator($schema, $metas, $options);
    }

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    public function biginteger(array $metas, array $options): Yup
    {
        $schema = Yup::mixed()
            ->transform(static fn (mixed $value, mixed $originalValue): mixed => self::toBigIntegerString($originalValue) ?? $value)
            ->test('is-biginteger', '${path} must be a valid integer', static fn (mixed $value): bool => $value === null || $value instanceof Undefined || self::isValidBigInteger($value));

        return $this->addUniqueValidator($schema, $metas, $options);
    }

    /** @param ValidatorMetas $metas @param ValidatorOptions $options */
    public function dates(array $metas, array $options): Yup
    {
        return $this->addUniqueValidator(Yup::mixed(), $metas, $options);
    }

    public function boolean(): YupBoolean
    {
        return Yup::boolean()->nullable();
    }

    public function json(): Yup
    {
        return Yup::mixed();
    }

    public function blocks(): YupArray
    {
        return BlocksValidator::blocksValidator();
    }

    public function has(string $type): bool
    {
        return in_array($type, [
            'string', 'text', 'richtext', 'password', 'email', 'enumeration', 'boolean', 'uid', 'json',
            'integer', 'biginteger', 'float', 'decimal', 'date', 'time', 'datetime', 'timestamp', 'blocks',
        ], true);
    }

    /**
     * `Validators[type](metas, options)`.
     *
     * @param ValidatorMetas $metas
     * @param ValidatorOptions $options
     */
    public function for(string $type, array $metas, array $options): Yup
    {
        return match ($type) {
            'string', 'text', 'richtext', 'password' => $this->string($metas, $options),
            'email' => $this->email($metas, $options),
            'enumeration' => $this->enumeration($metas),
            'boolean' => $this->boolean(),
            'uid' => $this->uid($metas, $options),
            'json' => $this->json(),
            'integer' => $this->integer($metas, $options),
            'biginteger' => $this->biginteger($metas, $options),
            'float', 'decimal' => $this->float($metas, $options),
            'date', 'time', 'datetime', 'timestamp' => $this->dates($metas, $options),
            'blocks' => $this->blocks(),
            default => Yup::mixed(),
        };
    }
}
