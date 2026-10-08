<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Queries;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Utils\Components;
use Strapi\Types\Schema\Schema as StrapiSchema;

/**
 * Port of src/strapi/queries/entity.ts: `createEntityQuery(strapi)` returns `(uid) => query`, and
 * that query is an instance of this class (`create`, `createMany`, `deleteMany`,
 * `getDeepPopulateComponentLikeQuery`, `deepPopulateComponentLikeQuery`).
 */
final class Entity
{
    private function __construct(private readonly Strapi $strapi, public readonly string $uid)
    {
    }

    /** @return \Closure(string): self */
    public static function createEntityQuery(Strapi $strapi): \Closure
    {
        return static fn (string $uid): self => new self($strapi, $uid);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function sanitizeComponentLikeAttributes(StrapiSchema $model, array $data): array
    {
        foreach ($model->attributes as $key => $attribute) {
            $type = $attribute['type'] ?? null;
            if ($type === 'component' || $type === 'dynamiczone') {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function model(string $uid): StrapiSchema
    {
        return $this->strapi->getModel($uid) ?? throw new \RuntimeException("Model {$uid} not found");
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function assignToEntity(array $data): array
    {
        $model = $this->model($this->uid);

        $entityComponents = Components::createComponents($this->strapi, $this->uid, $data);
        $dataWithoutComponents = self::sanitizeComponentLikeAttributes($model, $data);

        // lodash assign(entityComponents, dataWithoutComponents)
        return array_merge($entityComponents, $dataWithoutComponents);
    }

    /**
     * @param array<string, mixed> $params `{ data, populate?, select? }`
     *
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        $dataWithComponents = $this->assignToEntity(is_array($params['data'] ?? null) ? $params['data'] : []);
        unset($dataWithComponents['id']);

        $query = ['data' => $dataWithComponents];
        if (array_key_exists('select', $params)) {
            $query['select'] = $params['select'];
        }
        if (array_key_exists('populate', $params)) {
            $query['populate'] = $params['populate'];
        }

        return $this->strapi->db()->query($this->uid)->create($query);
    }

    /**
     * @param array<string, mixed> $params `{ data: list<array> }`
     *
     * @return array<string, mixed>
     */
    public function createMany(array $params): array
    {
        $data = [];
        foreach (is_array($params['data'] ?? null) ? $params['data'] : [] as $item) {
            $withComponents = $this->assignToEntity(is_array($item) ? $item : []);
            unset($withComponents['id']);
            $data[] = $withComponents;
        }

        return $this->strapi->db()->query($this->uid)->createMany(['data' => $data]);
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>|null
     */
    public function deleteMany(?array $params = null): ?array
    {
        $entitiesToDelete = $this->strapi->db()->query($this->uid)->findMany($params ?? []);

        if ($entitiesToDelete === []) {
            return null;
        }

        $componentsToDelete = array_map(fn (array $entityToDelete): array => Components::getComponents($this->strapi, $this->uid, $entityToDelete), $entitiesToDelete);

        $deletedEntities = $this->strapi->db()->query($this->uid)->deleteMany($params ?? []);
        foreach ($componentsToDelete as $compos) {
            Components::deleteComponents($this->strapi, $this->uid, $compos, ['loadComponents' => false]);
        }

        return $deletedEntities;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|list<string>
     */
    public function getDeepPopulateComponentLikeQuery(StrapiSchema $contentType, array $params = ['select' => '*']): array
    {
        $populate = [];

        foreach ($contentType->attributes as $key => $attribute) {
            $type = $attribute['type'] ?? null;

            if ($type === 'component') {
                $component = $this->model((string) $attribute['component']);
                $subPopulate = $this->getDeepPopulateComponentLikeQuery($component, $params);

                if ($subPopulate !== []) {
                    $populate[$key] = [...$params, 'populate' => $subPopulate];
                } else {
                    $populate[$key] = [...$params];
                }
            }

            if ($type === 'dynamiczone') {
                $on = [];

                foreach ($attribute['components'] ?? [] as $componentUID) {
                    $component = $this->model((string) $componentUID);
                    $subPopulate = $this->getDeepPopulateComponentLikeQuery($component, $params);

                    if ($subPopulate !== []) {
                        $on[$componentUID] = [...$params, 'populate' => $subPopulate];
                    } else {
                        $on[$componentUID] = [...$params];
                    }
                }

                $populate[$key] = $on !== [] ? ['on' => $on] : true;
            }
        }

        $allTrue = true;
        foreach ($populate as $value) {
            if ($value !== true) {
                $allTrue = false;
                break;
            }
        }

        if ($allTrue) {
            return array_keys($populate);
        }

        return $populate;
    }

    /** @return array<string, mixed>|list<string> */
    public function deepPopulateComponentLikeQuery(): array
    {
        return $this->getDeepPopulateComponentLikeQuery($this->model($this->uid));
    }
}
