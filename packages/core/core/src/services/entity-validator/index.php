<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Errors\YupValidationError;
use Strapi\Utils\Relations;

/**
 * Port of packages/core/core/src/services/entity-validator/index.ts: validates input data for
 * entity creation or update against the schema (`strapi.entityValidator`).
 *
 * `validateEntityCreation(model, data, options, entity)` / `validateEntityUpdate(...)` return the
 * cast data (defaults applied, numbers cast) or throw a {@see YupValidationError} with
 * `details.errors[] = { path, message, name, value }`.
 *
 * @phpstan-type ValidatorContext array{isDraft?: bool, locale?: string|null, strictRelations?: bool}
 * @phpstan-type ComponentContext array{parentContent: array{model: Schema|array<string, mixed>, id?: mixed, options?: ValidatorContext|null}, pathToComponent: list<string>, repeatableData: list<array<string, mixed>>, fullDynamicZoneContent?: list<array<string, mixed>>|null}
 * @phpstan-type AttributeMetas array{attr: array<string, mixed>, model: Schema|array<string, mixed>, updatedAttribute: array{name: string, value: mixed}, data: array<string, mixed>, entity?: array<string, mixed>|null, componentContext?: ComponentContext|null}
 */
final class EntityValidator
{
    private readonly Validators $validators;

    public function __construct(private readonly Strapi $strapi)
    {
        $this->validators = new Validators($strapi);
    }

    public function validators(): Validators
    {
        return $this->validators;
    }

    private static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && floor($value) === $value && is_finite($value));
    }

    /** @param array{attr: array<string, mixed>, updatedAttribute: array{name: string, value: mixed}} $meta */
    private static function addMinMax(YupArray $validator, array $meta): YupArray
    {
        $attr = $meta['attr'];
        $value = $meta['updatedAttribute']['value'];
        $nextValidator = $validator;

        if (
            self::isInteger($attr['min'] ?? null)
            && (($attr['required'] ?? false) || (is_array($value) && array_is_list($value) && $value !== []))
        ) {
            $nextValidator = $nextValidator->min((int) $attr['min']);
        }
        if (self::isInteger($attr['max'] ?? null)) {
            $nextValidator = $nextValidator->max((int) $attr['max']);
        }

        return $nextValidator;
    }

    /**
     * @template T of Yup
     * @param T $validator
     * @return T
     */
    private static function addRequiredValidation(string $createOrUpdate, Yup $validator, bool $required): Yup
    {
        if ($required) {
            if ($createOrUpdate === 'creation') {
                return $validator->notNil();
            }

            return $validator->notNull();
        }

        return $validator->nullable();
    }

    /** @param array<string, mixed> $attr */
    private static function addDefault(string $createOrUpdate, Yup $validator, array $attr): Yup
    {
        if ($createOrUpdate === 'creation') {
            if (((($attr['type'] ?? null) === 'component' && ($attr['repeatable'] ?? false)) || ($attr['type'] ?? null) === 'dynamiczone') && !($attr['required'] ?? false)) {
                return $validator->default([]);
            }

            return array_key_exists('default', $attr) ? $validator->default($attr['default']) : $validator;
        }

        return $validator;
    }

    private static function preventCast(Yup $validator): Yup
    {
        return $validator->transform(static fn (mixed $val, mixed $originalVal): mixed => $originalVal);
    }

    /**
     * @param array{attr: array<string, mixed>, updatedAttribute: array{name: string, value: mixed}, componentContext?: ComponentContext|null} $meta
     * @param ValidatorContext $options
     */
    private function createComponentValidator(string $createOrUpdate, array $meta, array $options): Yup
    {
        $attr = $meta['attr'];
        $updatedAttribute = $meta['updatedAttribute'];
        $componentContext = $meta['componentContext'] ?? null;

        $model = $this->strapi->getModel((string) $attr['component']);
        if ($model === null) {
            throw new \RuntimeException('Validation failed: Model not found');
        }

        if ($attr['repeatable'] ?? false) {
            $validator = Yup::array()->of(
                Yup::lazy(fn (mixed $item): Yup => $this->createModelValidator($createOrUpdate, ['componentContext' => $componentContext, 'model' => $model, 'data' => is_array($item) ? $item : []], $options)->notNull()),
            );

            $validator = self::addRequiredValidation($createOrUpdate, $validator, true);

            if (!($options['isDraft'] ?? false)) {
                $validator = self::addMinMax($validator, ['attr' => $attr, 'updatedAttribute' => $updatedAttribute]);
            }

            return $validator;
        }

        $validator = $this->createModelValidator($createOrUpdate, [
            'model' => $model,
            'data' => is_array($updatedAttribute['value']) ? $updatedAttribute['value'] : [],
            'componentContext' => $componentContext,
        ], $options);

        return self::addRequiredValidation($createOrUpdate, $validator, !($options['isDraft'] ?? false) && ($attr['required'] ?? false));
    }

    /**
     * @param array{attr: array<string, mixed>, updatedAttribute: array{name: string, value: mixed}, componentContext?: ComponentContext|null} $meta
     * @param ValidatorContext $options
     */
    private function createDzValidator(string $createOrUpdate, array $meta, array $options): Yup
    {
        $attr = $meta['attr'];
        $updatedAttribute = $meta['updatedAttribute'];
        $componentContext = $meta['componentContext'] ?? null;

        $validator = Yup::array()->of(Yup::lazy(function (mixed $item) use ($createOrUpdate, $componentContext, $options): Yup {
            $componentUid = is_array($item) ? ($item['__component'] ?? null) : null;
            $model = is_string($componentUid) ? $this->strapi->getModel($componentUid) : null;
            $schema = Yup::object([
                '__component' => Yup::string()->required()->oneOf(array_keys($this->strapi->components())),
            ])->notNull();

            return $model !== null
                ? $schema->concat($this->createModelValidator($createOrUpdate, ['model' => $model, 'data' => is_array($item) ? $item : [], 'componentContext' => $componentContext], $options))
                : $schema;
        }));

        $validator = self::addRequiredValidation($createOrUpdate, $validator, true);

        if (!($options['isDraft'] ?? false)) {
            $validator = self::addMinMax($validator, ['attr' => $attr, 'updatedAttribute' => $updatedAttribute]);
        }

        return $validator;
    }

    /**
     * A relation/media value can be supplied in several shapes: a bare id, an array of ids, or a
     * connect/set object. Normalises all of them to "does this attach at least one entry?".
     */
    public static function hasRelationValue(mixed $value): bool
    {
        if ($value === null || $value instanceof Undefined) {
            return false;
        }

        if (is_array($value) && array_is_list($value)) {
            return $value !== [];
        }

        if (is_array($value)) {
            $connect = $value['connect'] ?? null;
            $set = $value['set'] ?? null;

            if ($connect !== null) {
                return is_array($connect) && array_is_list($connect) ? $connect !== [] : true;
            }
            if ($set !== null) {
                return is_array($set) && array_is_list($set) ? $set !== [] : true;
            }

            // Any other object shape (e.g. a bare `{ id }`) counts as a value.
            return true;
        }

        return true;
    }

    /** A disconnect-only update payload removes entries without adding or replacing any. */
    public static function isDisconnectOnly(mixed $value): bool
    {
        if (!is_array($value) || array_is_list($value)) {
            return false;
        }

        $connect = $value['connect'] ?? null;
        $set = $value['set'] ?? null;
        $disconnect = $value['disconnect'] ?? null;

        if ($set !== null) {
            return false;
        }

        $hasAdditions = $connect !== null && (!(is_array($connect) && array_is_list($connect)) || $connect !== []);
        if ($hasAdditions) {
            return false;
        }

        return $disconnect !== null && (!(is_array($disconnect) && array_is_list($disconnect)) || $disconnect !== []);
    }

    /** Decides whether a required media/relation value should be rejected as empty. */
    public static function relationRequiredFails(string $createOrUpdate, mixed $value, bool $isToOne): bool
    {
        if ($createOrUpdate === 'creation') {
            if (self::isDisconnectOnly($value)) {
                return true;
            }
        }

        if ($createOrUpdate === 'update') {
            // Absent key on update = keep the existing value.
            if ($value instanceof Undefined) {
                return false;
            }

            if (self::isDisconnectOnly($value)) {
                return $isToOne;
            }

            // A connect-only empty is a no-op, not an emptying operation.
            if (is_array($value) && !array_is_list($value) && ($value['connect'] ?? null) !== null) {
                $connect = $value['connect'];
                $hasSet = ($value['set'] ?? null) !== null;
                if (!$hasSet && is_array($connect) && array_is_list($connect) && $connect === []) {
                    return false;
                }
            }
        }

        return !self::hasRelationValue($value);
    }

    /**
     * @param array{attr: array<string, mixed>, updatedAttribute: array{name: string, value: mixed}} $meta
     * @param ValidatorContext $options
     */
    private static function createMediaAttributeValidator(string $createOrUpdate, array $meta, array $options): Yup
    {
        $attr = $meta['attr'];
        $updatedAttribute = $meta['updatedAttribute'];
        $validator = Yup::mixed();

        if (($options['strictRelations'] ?? false) && !($options['isDraft'] ?? false) && ($attr['required'] ?? false)) {
            $isToOne = !($attr['multiple'] ?? false);
            $validator = $validator->test(
                'required-media',
                "{$updatedAttribute['name']} must be defined.",
                static fn (): bool => !self::relationRequiredFails($createOrUpdate, $updatedAttribute['value'], $isToOne),
            );
        }

        return $validator;
    }

    /**
     * @param array{attr: array<string, mixed>, updatedAttribute: array{name: string, value: mixed}} $meta
     * @param ValidatorContext $options
     */
    private static function createRelationValidator(string $createOrUpdate, array $meta, array $options): Yup
    {
        $attr = $meta['attr'];
        $updatedAttribute = $meta['updatedAttribute'];

        $validator = is_array($updatedAttribute['value']) && array_is_list($updatedAttribute['value'])
            ? Yup::array()->of(Yup::mixed())
            : Yup::mixed();

        if (($options['strictRelations'] ?? false) && !($options['isDraft'] ?? false) && ($attr['required'] ?? false)) {
            $isToOne = Relations::isAnyToOne($attr);
            $validator = $validator->test(
                'required-relation',
                "{$updatedAttribute['name']} must be defined.",
                static fn (): bool => !self::relationRequiredFails($createOrUpdate, $updatedAttribute['value'], $isToOne),
            );
        }

        return $validator;
    }

    /**
     * @param AttributeMetas $metas
     * @param ValidatorContext $options
     */
    private function createScalarAttributeValidator(string $createOrUpdate, array $metas, array $options): Yup
    {
        $type = (string) ($metas['attr']['type'] ?? '');
        $validatorOptions = ['isDraft' => (bool) ($options['isDraft'] ?? false), 'locale' => $options['locale'] ?? null];

        $validator = $this->validators->has($type)
            ? $this->validators->for($type, $metas, $validatorOptions)
            : Yup::mixed(); // No validators specified - fall back to mixed

        return self::addRequiredValidation($createOrUpdate, $validator, !($options['isDraft'] ?? false) && ($metas['attr']['required'] ?? false));
    }

    /**
     * @param AttributeMetas $metas
     * @param ValidatorContext $options
     */
    private function createAttributeValidator(string $createOrUpdate, array $metas, array $options): Yup
    {
        $attr = $metas['attr'];

        // If field is conditionally invisible, skip all validation for it
        if (isset($attr['conditions']['visible'])) {
            $isVisible = JsonLogic::truthy(JsonLogic::apply($attr['conditions']['visible'], is_array($metas['data'] ?? null) ? $metas['data'] : []));

            if (!$isVisible) {
                return Yup::mixed()->notRequired(); // Completely skip validation
            }
        }

        if (ContentTypes::isMediaAttribute($attr)) {
            $validator = self::createMediaAttributeValidator($createOrUpdate, ['attr' => $attr, 'updatedAttribute' => $metas['updatedAttribute']], $options);
        } elseif (ContentTypes::isScalarAttribute($attr)) {
            $validator = $this->createScalarAttributeValidator($createOrUpdate, $metas, $options);
        } else {
            $validator = Yup::mixed();
            $type = $attr['type'] ?? null;
            $componentContext = $metas['componentContext'] ?? null;

            if ($type === 'component' && $componentContext !== null) {
                $pathToComponent = [...($componentContext['pathToComponent'] ?? []), $metas['updatedAttribute']['name']];

                $repeatableData = ($attr['repeatable'] ?? false) && count($pathToComponent) === 1
                    ? (is_array($metas['updatedAttribute']['value']) ? array_values($metas['updatedAttribute']['value']) : [])
                    : $componentContext['repeatableData'];

                $newComponentContext = [...$componentContext, 'pathToComponent' => $pathToComponent, 'repeatableData' => $repeatableData];

                $validator = $this->createComponentValidator($createOrUpdate, [
                    'componentContext' => $newComponentContext,
                    'attr' => $attr,
                    'updatedAttribute' => $metas['updatedAttribute'],
                ], $options);
            } elseif ($type === 'dynamiczone' && $componentContext !== null) {
                $newComponentContext = [
                    ...$componentContext,
                    'fullDynamicZoneContent' => is_array($metas['updatedAttribute']['value']) ? array_values($metas['updatedAttribute']['value']) : [],
                    'pathToComponent' => [...($componentContext['pathToComponent'] ?? []), $metas['updatedAttribute']['name']],
                ];
                $metas['componentContext'] = $newComponentContext;

                $validator = $this->createDzValidator($createOrUpdate, $metas, $options);
            } elseif ($type === 'relation') {
                $validator = self::createRelationValidator($createOrUpdate, ['attr' => $attr, 'updatedAttribute' => $metas['updatedAttribute']], $options);
            }

            $validator = self::preventCast($validator);
        }

        return self::addDefault($createOrUpdate, $validator, $attr);
    }

    /**
     * @param array{componentContext?: ComponentContext|null, model: Schema|array<string, mixed>, data: array<string, mixed>, entity?: array<string, mixed>|null} $meta
     * @param ValidatorContext $options
     */
    private function createModelValidator(string $createOrUpdate, array $meta, array $options): YupObject
    {
        $model = $meta['model'];
        $data = $meta['data'];
        $writableAttributes = ContentTypes::getWritableAttributes($model);
        $attributes = ContentTypes::attributes($model);

        $shape = [];
        foreach ($writableAttributes as $attributeName) {
            $metas = [
                'attr' => $attributes[$attributeName],
                'updatedAttribute' => ['name' => $attributeName, 'value' => array_key_exists($attributeName, $data) ? $data[$attributeName] : Undefined::value()],
                'data' => $data,
                'model' => $model,
                'entity' => $meta['entity'] ?? null,
                'componentContext' => $meta['componentContext'] ?? null,
            ];

            $shape[$attributeName] = $this->createAttributeValidator($createOrUpdate, $metas, $options);
        }

        return Yup::object($shape);
    }

    /**
     * @param Schema|array<string, mixed> $model
     * @param mixed $data the submitted payload, expected to be an object (`array<string, mixed>`)
     * @param ValidatorContext|null $options
     * @param array<string, mixed>|null $entity
     * @return array<string, mixed>
     */
    private function validateEntity(string $createOrUpdate, Schema|array $model, mixed $data, ?array $options = null, ?array $entity = null): array
    {
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            $displayName = ContentTypes::info($model)['displayName'] ?? '';
            $type = is_array($data) ? 'object' : (is_null($data) ? 'undefined' : get_debug_type($data));

            throw new ValidationError("Invalid payload submitted for the {$createOrUpdate} of an entity of type {$displayName}. Expected an object, but got {$type}");
        }

        $validator = $this->createModelValidator($createOrUpdate, [
            'model' => $model,
            'data' => $data,
            'entity' => $entity,
            'componentContext' => [
                'parentContent' => ['id' => $entity['id'] ?? null, 'model' => $model, 'options' => $options],
                'pathToComponent' => [],
                'repeatableData' => [],
            ],
        ], [
            'isDraft' => $options['isDraft'] ?? false,
            'locale' => $options['locale'] ?? null,
            'strictRelations' => $options['strictRelations'] ?? false,
        ])
            ->test('relations-test', 'check that all relations exist', function (mixed $data, TestContext $ctx) use ($model): bool|YupError {
                try {
                    $this->checkRelationsExist($this->buildRelationsStore(ContentTypes::uid($model) ?? '', is_array($data) ? $data : null));
                } catch (ValidationError $e) {
                    return $ctx->createError(['path' => $ctx->path, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Invalid relations']);
                }

                return true;
            })
            ->required();

        /** @var array<string, mixed> $result */
        $result = $validator->validate($data, ['strict' => false, 'abortEarly' => false]);

        return $result;
    }

    /**
     * @param Schema|array<string, mixed> $model
     * @param ValidatorContext|null $options
     * @param array<string, mixed>|null $entity
     * @return array<string, mixed>
     */
    public function validateEntityCreation(Schema|array $model, mixed $data, ?array $options = null, ?array $entity = null): array
    {
        return $this->validateEntity('creation', $model, $data, $options, $entity);
    }

    /**
     * @param Schema|array<string, mixed> $model
     * @param ValidatorContext|null $options
     * @param array<string, mixed>|null $entity
     * @return array<string, mixed>
     */
    public function validateEntityUpdate(Schema|array $model, mixed $data, ?array $options = null, ?array $entity = null): array
    {
        return $this->validateEntity('update', $model, $data, $options, $entity);
    }

    /**
     * Builds an object containing all the media and relations being associated with an entity.
     *
     * @param array<string, mixed>|null $data
     * @return array<string, list<array{id: mixed}>>
     */
    public function buildRelationsStore(string $uid, ?array $data): array
    {
        if ($uid === '') {
            throw new ValidationError('Cannot build relations store: "uid" is undefined');
        }

        if ($data === null || $data === []) {
            return [];
        }

        $currentModel = $this->strapi->getModel($uid);
        if ($currentModel === null) {
            return [];
        }

        $result = [];
        foreach ($currentModel->attributes as $attributeName => $attribute) {
            $value = $data[$attributeName] ?? null;

            if ($value === null) {
                continue;
            }

            switch ($attribute['type'] ?? null) {
                case 'relation':
                case 'media':
                    if ($attribute['type'] === 'relation' && in_array($attribute['relation'] ?? null, ['morphToMany', 'morphToOne'], true)) {
                        // TODO: handle polymorphic relations
                        break;
                    }

                    $target = $attribute['type'] === 'media' ? 'plugin::upload.file' : (string) $attribute['target'];

                    if (is_array($value) && array_is_list($value)) {
                        $source = $value;
                    } elseif (is_array($value)) {
                        if (($value['connect'] ?? null) !== null) {
                            $source = is_array($value['connect']) && array_is_list($value['connect']) ? $value['connect'] : [$value['connect']];
                        } elseif (($value['set'] ?? null) !== null) {
                            $source = is_array($value['set']) && array_is_list($value['set']) ? $value['set'] : [$value['set']];
                        } else {
                            $source = [];
                        }
                    } else {
                        $source = [$value];
                    }

                    $result[$target] ??= [];
                    foreach ($source as $v) {
                        $result[$target][] = ['id' => is_array($v) ? ($v['id'] ?? null) : $v];
                    }
                    break;

                case 'component':
                    foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $componentValue) {
                        if (empty($attribute['component'])) {
                            throw new ValidationError('Cannot build relations store from component, component identifier is undefined');
                        }
                        $result = self::mergeStores($result, $this->buildRelationsStore((string) $attribute['component'], is_array($componentValue) ? $componentValue : null));
                    }
                    break;

                case 'dynamiczone':
                    foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $dzValue) {
                        $dzValue = is_array($dzValue) ? $dzValue : [];
                        if (empty($dzValue['__component'])) {
                            throw new ValidationError('Cannot build relations store from dynamiczone, component identifier is undefined');
                        }
                        $result = self::mergeStores($result, $this->buildRelationsStore((string) $dzValue['__component'], $dzValue));
                    }
                    break;

                default:
                    break;
            }
        }

        return $result;
    }

    /**
     * @param array<string, list<array{id: mixed}>> $a
     * @param array<string, list<array{id: mixed}>> $b
     * @return array<string, list<array{id: mixed}>>
     */
    private static function mergeStores(array $a, array $b): array
    {
        foreach ($b as $key => $values) {
            $a[$key] = [...($a[$key] ?? []), ...$values];
        }

        return $a;
    }

    /**
     * Iterate through the relations store and validates that every relation or media mentioned exists.
     *
     * @param array<string, list<array{id: mixed}>> $relationsStore
     */
    public function checkRelationsExist(array $relationsStore = []): void
    {
        foreach ($relationsStore as $key => $value) {
            $uniqueIds = [];
            foreach ($value as $v) {
                $id = $v['id'];
                // relations without an id (documentId only) are resolved by the document service; skip them here
                if ($id === null) {
                    continue;
                }
                $uniqueIds[(string) $id] = $id;
            }
            if ($uniqueIds === []) {
                continue;
            }

            $count = $this->strapi->db()->query($key)->count(['where' => ['id' => ['$in' => array_values($uniqueIds)]]]);

            if ($count !== count($uniqueIds)) {
                throw new ValidationError((count($uniqueIds) - $count) . " relation(s) of type {$key} associated with this entity do not exist");
            }
        }
    }
}
