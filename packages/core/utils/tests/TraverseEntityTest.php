<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\Traverse\Path;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\TraverseEntity;

/** Port of __tests__/traverse-entity.test.ts. */
final class TraverseEntityTest extends TestCase
{
    /** @var list<VisitorOptions> */
    private array $calls = [];

    /** @var list<string> */
    private array $modelCalls = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $modelsByUid = null;

    /** @var list<array<string, mixed>> */
    private array $modelQueue = [];

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function base(array $attributes = []): array
    {
        return ['modelType' => 'contentType', 'uid' => 'api::test.test', 'kind' => 'collectionType', 'info' => ['displayName' => 'Test', 'singularName' => 'test', 'pluralName' => 'tests'], 'attributes' => $attributes];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function component(array $attributes = []): array
    {
        return ['modelType' => 'component', 'uid' => 'default.test-component', 'info' => ['displayName' => 'Test Component'], 'attributes' => $attributes];
    }

    /** @return array<string, mixed> */
    private static function media(): array
    {
        return ['modelType' => 'contentType', 'uid' => 'plugin::upload.file', 'kind' => 'collectionType', 'info' => ['displayName' => 'File', 'singularName' => 'file', 'pluralName' => 'files'], 'attributes' => ['name' => ['type' => 'string'], 'url' => ['type' => 'string'], 'mime' => ['type' => 'string']]];
    }

    protected function setUp(): void
    {
        $this->calls = [];
        $this->modelCalls = [];
        $this->modelsByUid = null;
        $this->modelQueue = [];
    }

    private function visitor(): \Closure
    {
        return function (VisitorOptions $options, VisitorUtils $utils): void {
            $this->calls[] = $options;
        };
    }

    private function getModel(): \Closure
    {
        return function (string $uid): ?array {
            $this->modelCalls[] = $uid;
            if ($this->modelQueue !== []) {
                return array_shift($this->modelQueue);
            }

            return $this->modelsByUid[$uid] ?? ($this->modelsByUid['*'] ?? null);
        };
    }

    /** @return list<VisitorOptions> */
    private function callsFor(string $key): array
    {
        return array_values(array_filter($this->calls, static fn (VisitorOptions $o): bool => $o->key === $key));
    }

    public function testShouldCloneEntityAndNotMutateOriginal(): void
    {
        $entity = ['id' => 1, 'title' => 'Test'];
        $result = TraverseEntity::traverse(function (VisitorOptions $o, VisitorUtils $u): void {
            $u->set('title', 'changed');
        }, ['schema' => self::base(['title' => ['type' => 'string']]), 'getModel' => $this->getModel()], $entity);

        self::assertSame(['id' => 1, 'title' => 'changed'], $result);
        self::assertSame(['id' => 1, 'title' => 'Test'], $entity);
    }

    public function testShouldCallVisitorForEachAttribute(): void
    {
        $schema = self::base(['title' => ['type' => 'string'], 'description' => ['type' => 'text'], 'count' => ['type' => 'integer']]);
        TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['id' => 1, 'title' => 'Test', 'description' => 'Description', 'count' => 5]);

        self::assertCount(4, $this->calls);
    }

    public function testShouldReturnEntityUnchangedForNullSchema(): void
    {
        $entity = ['id' => 1, 'title' => 'Test'];
        self::assertSame($entity, TraverseEntity::traverse($this->visitor(), ['schema' => null, 'getModel' => $this->getModel()], $entity));
        self::assertSame([], $this->calls);
    }

    public function testShouldReturnEntityUnchangedForNonObjectEntity(): void
    {
        $options = ['schema' => self::base(), 'getModel' => $this->getModel()];
        self::assertNull(TraverseEntity::traverse($this->visitor(), $options, null));
        self::assertSame('string', TraverseEntity::traverse($this->visitor(), $options, 'string'));
        self::assertSame(123, TraverseEntity::traverse($this->visitor(), $options, 123));
        self::assertSame([], $this->calls);
    }

    public function testShouldBuildRawPathCorrectly(): void
    {
        TraverseEntity::traverse($this->visitor(), ['schema' => self::base(['level1' => ['type' => 'string']]), 'getModel' => $this->getModel()], ['level1' => 'value']);

        $path = $this->calls[0]->path;
        self::assertSame(['raw' => 'level1', 'attribute' => 'level1', 'rawWithIndices' => 'level1'], $path->toArray());
    }

    public function testShouldInheritPathFromParent(): void
    {
        TraverseEntity::traverse($this->visitor(), ['schema' => self::base(['field' => ['type' => 'string']]), 'path' => new Path('parent', 'parent', 'parent'), 'getModel' => $this->getModel()], ['field' => 'value']);

        self::assertSame(['raw' => 'parent.field', 'attribute' => 'parent.field', 'rawWithIndices' => 'parent.field'], $this->calls[0]->path->toArray());
    }

    public function testShouldNotBuildAttributePathForFieldsNotInSchema(): void
    {
        TraverseEntity::traverse($this->visitor(), ['schema' => self::base(['validField' => ['type' => 'string']]), 'getModel' => $this->getModel()], ['validField' => 'value', 'extraField' => 'extra']);

        self::assertSame('validField', $this->callsFor('validField')[0]->path->attribute);
        self::assertNull($this->callsFor('extraField')[0]->path->attribute);
    }

    public function testShouldAllowVisitorToRemoveFields(): void
    {
        $result = TraverseEntity::traverse(static function (VisitorOptions $o, VisitorUtils $u): void {
            if ($o->key === 'remove') {
                $u->remove($o->key);
            }
        }, ['schema' => self::base(['keep' => ['type' => 'string'], 'remove' => ['type' => 'string']]), 'getModel' => $this->getModel()], ['keep' => 'keep', 'remove' => 'remove']);

        self::assertSame(['keep' => 'keep'], $result);
    }

    public function testShouldAllowVisitorToSetFieldValues(): void
    {
        $result = TraverseEntity::traverse(static function (VisitorOptions $o, VisitorUtils $u): void {
            if ($o->key === 'field') {
                $u->set($o->key, 'modified');
            }
        }, ['schema' => self::base(['field' => ['type' => 'string']]), 'getModel' => $this->getModel()], ['field' => 'original']);

        self::assertSame('modified', $result['field']);
    }

    public function testShouldTraverseOneToOneRelation(): void
    {
        $related = self::base(['name' => ['type' => 'string']]);
        $this->modelsByUid = ['*' => $related];
        $schema = self::base(['relation' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::related.related']]);

        TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['relation' => ['id' => 1, 'name' => 'Related']]);

        self::assertContains('api::related.related', $this->modelCalls);
        self::assertSame($related, $this->callsFor('name')[0]->schema);
    }

    public function testShouldTraverseOneToManyRelationArray(): void
    {
        $this->modelsByUid = ['*' => self::base(['name' => ['type' => 'string']])];
        $schema = self::base(['relations' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'api::related.related']]);

        TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['relations' => [['id' => 1, 'name' => 'First'], ['id' => 2, 'name' => 'Second']]]);

        $paths = array_map(static fn (VisitorOptions $o): ?string => $o->path->rawWithIndices, $this->callsFor('name'));
        self::assertSame(['relations.0.name', 'relations.1.name'], $paths);
    }

    public function testShouldTraverseMorphToRelation(): void
    {
        $target = self::base(['title' => ['type' => 'string']]);
        $this->modelsByUid = ['*' => $target];
        $schema = self::base(['morphRelation' => ['type' => 'relation', 'relation' => 'morphToOne']]);

        TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['morphRelation' => ['__type' => 'api::target.target', 'id' => 1, 'title' => 'Morph Target']]);

        self::assertContains('api::target.target', $this->modelCalls);
        self::assertSame($target, $this->callsFor('title')[0]->schema);
    }

    public function testShouldSkipNullRelations(): void
    {
        $schema = self::base(['relation' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::related.related']]);
        TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['relation' => null]);

        self::assertSame([], $this->modelCalls);
        self::assertCount(1, $this->callsFor('relation'));
        self::assertNull($this->callsFor('relation')[0]->value);
    }

    public function testShouldTraverseMediaAttribute(): void
    {
        $media = self::media();
        $this->modelsByUid = ['*' => $media];
        TraverseEntity::traverse($this->visitor(), ['schema' => self::base(['image' => ['type' => 'media']]), 'getModel' => $this->getModel()], ['image' => ['id' => 1, 'name' => 'image.jpg', 'url' => '/uploads/image.jpg']]);

        self::assertContains('plugin::upload.file', $this->modelCalls);
        self::assertSame($media, $this->callsFor('name')[0]->schema);
    }

    public function testShouldTraverseMediaArray(): void
    {
        $this->modelsByUid = ['*' => self::media()];
        TraverseEntity::traverse($this->visitor(), ['schema' => self::base(['images' => ['type' => 'media']]), 'getModel' => $this->getModel()], ['images' => [['id' => 1, 'name' => 'first.jpg'], ['id' => 2, 'name' => 'second.jpg']]]);

        $paths = array_map(static fn (VisitorOptions $o): ?string => $o->path->rawWithIndices, $this->callsFor('name'));
        self::assertSame(['images.0.name', 'images.1.name'], $paths);
    }

    public function testShouldTraverseComponentAttribute(): void
    {
        $component = self::component(['text' => ['type' => 'string'], 'number' => ['type' => 'integer']]);
        $this->modelsByUid = ['*' => $component];
        TraverseEntity::traverse($this->visitor(), ['schema' => self::base(['component' => ['type' => 'component', 'component' => 'default.test-component']]), 'getModel' => $this->getModel()], ['component' => ['text' => 'Hello', 'number' => 42]]);

        self::assertContains('default.test-component', $this->modelCalls);
        self::assertSame($component, $this->callsFor('text')[0]->schema);
        self::assertSame($component, $this->callsFor('number')[0]->schema);
    }

    public function testShouldTraverseRepeatableComponent(): void
    {
        $this->modelsByUid = ['*' => self::component(['title' => ['type' => 'string']])];
        TraverseEntity::traverse($this->visitor(), ['schema' => self::base(['components' => ['type' => 'component', 'component' => 'default.test-component', 'repeatable' => true]]), 'getModel' => $this->getModel()], ['components' => [['title' => 'First'], ['title' => 'Second']]]);

        $paths = array_map(static fn (VisitorOptions $o): ?string => $o->path->rawWithIndices, $this->callsFor('title'));
        self::assertSame(['components.0.title', 'components.1.title'], $paths);
    }

    public function testShouldTraverseDynamicZoneEntries(): void
    {
        $c1 = self::component(['text' => ['type' => 'string']]);
        $c2 = self::component(['number' => ['type' => 'integer']]);
        $this->modelQueue = [$c1, $c2];
        $schema = self::base(['dynamicZone' => ['type' => 'dynamiczone', 'components' => ['default.text-component', 'default.number-component']]]);

        TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['dynamicZone' => [
            ['__component' => 'default.text-component', 'text' => 'Hello'],
            ['__component' => 'default.number-component', 'number' => 123],
        ]]);

        self::assertSame(['default.text-component', 'default.number-component'], $this->modelCalls);
        self::assertSame($c1, $this->callsFor('text')[0]->schema);
        self::assertSame($c2, $this->callsFor('number')[0]->schema);
    }

    public function testShouldHandleEmptyDynamicZone(): void
    {
        $schema = self::base(['dynamicZone' => ['type' => 'dynamiczone', 'components' => ['default.test-component']]]);
        $result = TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['dynamicZone' => []]);

        self::assertSame([], $result['dynamicZone']);
        self::assertSame([], $this->modelCalls);
    }

    public function testShouldNotCrashOnANullDynamicZoneEntry(): void
    {
        $component = self::component(['text' => ['type' => 'string']]);
        $this->modelsByUid = ['*' => $component];
        $schema = self::base(['dynamicZone' => ['type' => 'dynamiczone', 'components' => ['default.text-component']]]);

        $result = TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['dynamicZone' => [null, ['__component' => 'default.text-component', 'text' => 'Hello']]]);

        self::assertNull($result['dynamicZone'][0]);
        self::assertContains('default.text-component', $this->modelCalls);
        self::assertSame($component, $this->callsFor('text')[0]->schema);
    }

    public function testShouldTrackArrayIndicesInRawWithIndicesPath(): void
    {
        $this->modelsByUid = ['*' => self::base(['name' => ['type' => 'string']])];
        $schema = self::base(['items' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'api::related.related']]);
        TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['items' => [['name' => 'Item 1'], ['name' => 'Item 2'], ['name' => 'Item 3']]]);

        $indices = array_map(static function (VisitorOptions $o): int {
            preg_match('/items\.(\d+)\.name/', (string) $o->path->rawWithIndices, $m);

            return (int) $m[1];
        }, $this->callsFor('name'));
        self::assertSame([0, 1, 2], $indices);
    }

    public function testShouldPreserveRawWithIndicesWhenNoArraysInPath(): void
    {
        $this->modelsByUid = ['*' => self::component(['field' => ['type' => 'string']])];
        TraverseEntity::traverse($this->visitor(), ['schema' => self::base(['component' => ['type' => 'component', 'component' => 'default.test-component']]), 'getModel' => $this->getModel()], ['component' => ['field' => 'value']]);

        $call = $this->callsFor('field')[0];
        self::assertSame('component.field', $call->path->raw);
        self::assertSame('component.field', $call->path->rawWithIndices);
    }

    public function testShouldPassParentContextToNestedTraversals(): void
    {
        $this->modelsByUid = ['*' => self::base(['name' => ['type' => 'string']])];
        $schema = self::base(['relation' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::related.related']]);
        TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['relation' => ['name' => 'x']]);

        $parent = $this->callsFor('name')[0]->parent;
        self::assertNotNull($parent);
        self::assertSame('relation', $parent->key);
        self::assertSame($schema, $parent->schema);
        self::assertSame('relation', $parent->path->raw);
        self::assertNull($this->callsFor('relation')[0]->parent);
    }

    public function testShouldHandleDeeplyNestedStructures(): void
    {
        $deep = self::base(['value' => ['type' => 'string']]);
        $middle = self::base(['deep' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::deep.deep']]);
        $this->modelsByUid = ['api::middle.middle' => $middle, 'api::deep.deep' => $deep];
        $schema = self::base(['middle' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::middle.middle']]);

        TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['middle' => ['deep' => ['value' => 'x']]]);

        self::assertSame('middle.deep.value', $this->callsFor('value')[0]->path->attribute);
    }

    public function testShouldHandleMixedNullAndValidValuesInArrays(): void
    {
        $this->modelsByUid = ['*' => self::base(['name' => ['type' => 'string']])];
        $schema = self::base(['items' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'api::related.related']]);

        $result = TraverseEntity::traverse($this->visitor(), ['schema' => $schema, 'getModel' => $this->getModel()], ['items' => [['name' => 'a'], null, ['name' => 'b']]]);

        self::assertSame([['name' => 'a'], null, ['name' => 'b']], $result['items']);
        self::assertCount(2, $this->callsFor('name'));
    }

    public function testCurriedForm(): void
    {
        $traverse = TraverseEntity::create($this->visitor(), ['schema' => self::base(['field' => ['type' => 'string']]), 'getModel' => $this->getModel()]);

        self::assertSame(['field' => 'value'], $traverse(['field' => 'value']));
        self::assertCount(1, $this->calls);
    }
}
