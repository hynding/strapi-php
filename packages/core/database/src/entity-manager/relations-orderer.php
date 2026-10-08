<?php

declare(strict_types=1);

namespace Strapi\Database\EntityManager;

use Strapi\Database\Errors\InvalidRelationError;

/**
 * Port of packages/core/database/src/entity-manager/relations-orderer.ts.
 *
 * Calculates the order of relations when connecting them with positional attributes
 * (`before`, `after`, `start`, `end`). See upstream for the worked example.
 *
 * A link is `{ id, position?: { before?, after?, start?, end? }, __component?, init?, order? }`; the
 * input is user data so it is typed loosely (`Link`), the computed relations always carry an `order`.
 *
 * @phpstan-type Link array<string, mixed>
 * @phpstan-type ComputedLink array<string, mixed>
 */
final class RelationsOrderer
{
    /** @var list<ComputedLink> */
    private array $computedRelations = [];

    private float $maxOrder;

    /**
     * @param list<array<string, mixed>> $initArr
     * @param bool|null $strict if true, throw when a relation is connected adjacent to one that does not exist
     */
    public function __construct(array $initArr, string $idColumn, string $orderColumn, private readonly ?bool $strict = null)
    {
        foreach ($initArr as $r) {
            $this->computedRelations[] = [
                'init' => true,
                'id' => $r[$idColumn],
                'order' => isset($r[$orderColumn]) ? (float) $r[$orderColumn] : 1.0,
            ];
        }
        usort($this->computedRelations, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $this->maxOrder = 0.0;
        foreach ($this->computedRelations as $r) {
            $this->maxOrder = max($this->maxOrder, $r['order']);
        }
    }

    /** @param list<array<string, mixed>> $initArr */
    public static function create(array $initArr, string $idColumn, string $orderColumn, ?bool $strict = null): self
    {
        return new self($initArr, $idColumn, $orderColumn, $strict);
    }

    /**
     * When connecting relations, the order you connect them matters: a relation positioned before
     * another one in the same payload must be connected after it. Sorts the connect array so that
     * every adjacent relation is already known (in the DB or earlier in the array).
     *
     * @param list<Link> $connectArr
     * @param list<Link> $initialArr
     *
     * @return list<Link>
     */
    public static function sortConnectArray(array $connectArr, array $initialArr = [], bool $strictSort = true): array
    {
        $sortedConnect = [];
        $needsSorting = false;

        $relationInInitialArray = [];
        foreach ($initialArr as $rel) {
            $relationInInitialArray[(string) $rel['id']] = true;
        }

        // Map to store the first index where a relation id is connected
        $mappedRelations = [];
        foreach ($connectArr as $relation) {
            $adjacentRelId = $relation['position']['before'] ?? $relation['position']['after'] ?? null;

            if (!$adjacentRelId || (!isset($relationInInitialArray[(string) $adjacentRelId]) && !isset($mappedRelations[(string) $adjacentRelId]))) {
                $needsSorting = true;
            }

            $existingRelation = $mappedRelations[(string) $relation['id']] ?? null;
            $hasNoComponent = $existingRelation !== null && !array_key_exists('__component', $existingRelation);
            $hasSameComponent = $existingRelation !== null && ($existingRelation['__component'] ?? null) === ($relation['__component'] ?? null);

            if ($existingRelation !== null && ($hasNoComponent || $hasSameComponent)) {
                throw new InvalidRelationError("The relation with id {$relation['id']} is already connected. You cannot connect the same relation twice.");
            }

            // upstream: `{ [relation.id]: {...relation, computed: false}, ...mapper }` — first wins
            // (the `computed` flag is tracked separately in `$computed`)
            if ($existingRelation === null) {
                $mappedRelations[(string) $relation['id']] = $relation;
            }
        }

        if (!$needsSorting) {
            return $connectArr;
        }

        $computed = [];
        foreach ($connectArr as $relation) {
            self::computeRelation($relation, [], $mappedRelations, $computed, $sortedConnect, $relationInInitialArray, $strictSort);
        }

        return $sortedConnect;
    }

    /**
     * Recursive step of {@see sortConnectArray}: pushes `$relation` to `$sortedConnect` once the relation it
     * is positioned next to has been pushed.
     *
     * @param Link $relation
     * @param array<string, true> $relationsSeenInBranch
     * @param array<string, Link> $mappedRelations
     * @param array<string, true> $computed ids already pushed to `$sortedConnect`
     * @param list<Link> $sortedConnect
     * @param array<string, true> $relationInInitialArray
     */
    private static function computeRelation(array $relation, array $relationsSeenInBranch, array $mappedRelations, array &$computed, array &$sortedConnect, array $relationInInitialArray, bool $strictSort): void
    {
        $adjacentRelId = $relation['position']['before'] ?? $relation['position']['after'] ?? null;
        $adjacentRelation = $adjacentRelId !== null ? ($mappedRelations[(string) $adjacentRelId] ?? null) : null;

        if ($adjacentRelId && isset($relationsSeenInBranch[(string) $adjacentRelId])) {
            throw new InvalidRelationError(
                'A circular reference was found in the connect array. One relation is trying to connect before/after another one that is trying to connect before/after it',
            );
        }

        if (isset($computed[(string) $relation['id']])) {
            return;
        }

        $computed[(string) $relation['id']] = true;

        if (!$adjacentRelId || isset($relationInInitialArray[(string) $adjacentRelId])) {
            $sortedConnect[] = $relation;

            return;
        }

        if ($adjacentRelation !== null) {
            self::computeRelation($adjacentRelation, [...$relationsSeenInBranch, (string) $relation['id'] => true], $mappedRelations, $computed, $sortedConnect, $relationInInitialArray, $strictSort);
            $sortedConnect[] = $relation;
        } elseif ($strictSort) {
            throw new InvalidRelationError(sprintf(
                'There was a problem connecting relation with id %s at position %s. The relation with id %s needs to be connected first.',
                $relation['id'],
                json_encode($relation['position'] ?? null, JSON_UNESCAPED_SLASHES),
                $adjacentRelId,
            ));
        } else {
            $sortedConnect[] = ['id' => $relation['id'], 'position' => ['end' => true]];
        }
    }

    /** @return array{idx: int, relation: ComputedLink|null} */
    private function findRelation(mixed $id): array
    {
        foreach ($this->computedRelations as $idx => $r) {
            if ((string) $r['id'] === (string) $id) {
                return ['idx' => $idx, 'relation' => $r];
            }
        }

        return ['idx' => -1, 'relation' => null];
    }

    /** @param Link $r */
    private function removeRelation(array $r): void
    {
        $idx = $this->findRelation($r['id'])['idx'];
        if ($idx >= 0) {
            array_splice($this->computedRelations, $idx, 1);
        }
    }

    /** @param Link $r */
    private function insertRelation(array $r): void
    {
        $position = is_array($r['position'] ?? null) ? $r['position'] : [];

        if (!empty($position['before'])) {
            ['idx' => $beforeIdx, 'relation' => $relation] = $this->findRelation($position['before']);
            if ($relation === null) {
                throw new \RuntimeException('adjacent relation not found');
            }
            $order = !empty($relation['init']) ? $relation['order'] - 0.5 : $relation['order'];
            $idx = $beforeIdx;
        } elseif (!empty($position['after'])) {
            ['idx' => $afterIdx, 'relation' => $relation] = $this->findRelation($position['after']);
            if ($relation === null) {
                throw new \RuntimeException('adjacent relation not found');
            }
            $order = !empty($relation['init']) ? $relation['order'] + 0.5 : $relation['order'];
            $idx = $afterIdx + 1;
        } elseif (!empty($position['start'])) {
            if ($this->computedRelations !== []) {
                $first = $this->computedRelations[0];
                $order = !empty($first['init']) ? $first['order'] - 0.5 : $first['order'];
            } else {
                $order = 0.5;
            }
            $idx = 0;
        } else {
            $order = $this->maxOrder + 0.5;
            $idx = count($this->computedRelations);
        }

        $computed = $r;
        $computed['id'] = $r['id'] ?? null;
        $computed['order'] = $order;

        array_splice($this->computedRelations, $idx, 0, [$computed]);
    }

    /** @param list<Link> $relations */
    public function disconnect(array $relations): static
    {
        foreach ($relations as $relation) {
            $this->removeRelation($relation);
        }

        return $this;
    }

    /** @param list<Link> $relations */
    public function connect(array $relations): static
    {
        foreach (self::sortConnectArray($relations, $this->computedRelations, $this->strict ?? true) as $relation) {
            $this->disconnect([$relation]);

            try {
                $this->insertRelation($relation);
            } catch (\RuntimeException) {
                throw new \RuntimeException(sprintf(
                    'There was a problem connecting relation with id %s at position %s. The list of connect relations is not valid',
                    $relation['id'],
                    json_encode($relation['position'] ?? null, JSON_UNESCAPED_SLASHES),
                ));
            }
        }

        return $this;
    }

    /** @return list<ComputedLink> */
    public function get(): array
    {
        return $this->computedRelations;
    }

    /**
     * Get a map between the relation id and its order.
     *
     * @return array<string, float>
     */
    public function getOrderMap(): array
    {
        $map = [];
        $chunks = [];
        foreach ($this->computedRelations as $relation) {
            $chunks[(string) $relation['order']][] = $relation;
        }

        $sortedKeys = array_keys($chunks);
        usort($sortedKeys, static fn (int|string $a, int|string $b): int => (float) $a <=> (float) $b);

        foreach ($sortedKeys as $orderStr) {
            $relations = $chunks[$orderStr];
            $offset = 1;
            foreach ($relations as $relation) {
                if (empty($relation['init'])) {
                    $map[(string) $relation['id']] = (float) $orderStr + ($offset / (count($relations) + 1)) * 0.49;
                    $offset++;
                }
            }
        }

        return $map;
    }
}
