<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\AuthScope;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Sanitize\Sanitizers;
use Strapi\Utils\Sanitize\Visitors\RemovePassword;
use Strapi\Utils\Sanitize\Visitors\RemovePrivate;
use Strapi\Utils\Sanitize\Visitors\RemoveRestrictedRelations;
use Strapi\Utils\Sanitize\Visitors\RemoveUnrecognizedFields;
use Strapi\Utils\Traverse\Path;
use Strapi\Utils\Traverse\QueryFilters;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\TraverseEntity;

/** Port of __tests__/sanitize.visitors.test.ts. */
final class SanitizeVisitorsTest extends TestCase
{
    /** @var array{schema: array<string, mixed>, getModel: \Closure} */
    private array $ctx;

    protected function setUp(): void
    {
        $this->ctx = ['schema' => TestFixtures::ARTICLE_MODEL, 'getModel' => TestFixtures::getModelFn()];
        AuthScope::setVerifier(null);
        AuthScope::setRegisteredContentTypes(null);
    }

    protected function tearDown(): void
    {
        AuthScope::setVerifier(null);
        AuthScope::setRegisteredContentTypes(null);
    }

    public function testRemovePrivateRemovesPrivateFieldsInRelationalFilters(): void
    {
        $visitor = new RemovePrivate();
        self::assertSame(['updatedBy' => []], QueryFilters::traverse($visitor, $this->ctx, ['updatedBy' => ['resetPasswordToken' => ['$startsWith' => 'abc']]]));
        self::assertSame(['updatedBy' => []], QueryFilters::traverse($visitor, $this->ctx, ['updatedBy' => ['email' => ['$contains' => 'admin@']]]));
        self::assertSame(['createdBy' => []], QueryFilters::traverse($visitor, $this->ctx, ['createdBy' => ['isActive' => true]]));
        self::assertSame(['updatedBy' => ['firstname' => 'John']], QueryFilters::traverse($visitor, $this->ctx, ['updatedBy' => ['firstname' => 'John']]));
        self::assertSame(
            ['updatedBy' => ['firstname' => 'John']],
            QueryFilters::traverse($visitor, $this->ctx, ['updatedBy' => ['firstname' => 'John', 'email' => ['$contains' => 'admin@'], 'resetPasswordToken' => ['$startsWith' => 'abc']]]),
        );
    }

    public function testRemovePasswordRemovesPasswordFieldsInRelationalFilters(): void
    {
        self::assertSame(['createdBy' => []], QueryFilters::traverse(new RemovePassword(), $this->ctx, ['createdBy' => ['password' => ['$startsWith' => '$2b$']]]));
    }

    public function testDefaultSanitizeFilters(): void
    {
        self::assertSame(['updatedBy' => ['firstname' => 'John']], Sanitizers::defaultSanitizeFilters($this->ctx, ['updatedBy' => ['resetPasswordToken' => ['$startsWith' => 'abc'], 'firstname' => 'John']]));
        self::assertSame(['createdBy' => ['lastname' => 'Doe']], Sanitizers::defaultSanitizeFilters($this->ctx, ['createdBy' => ['password' => ['$startsWith' => '$2b$'], 'lastname' => 'Doe']]));
        self::assertSame(
            ['updatedBy' => ['firstname' => 'John', 'lastname' => 'Doe']],
            Sanitizers::defaultSanitizeFilters($this->ctx, ['updatedBy' => ['email' => ['$contains' => 'admin@'], 'password' => ['$startsWith' => '$2b$'], 'resetPasswordToken' => ['$startsWith' => 'abc'], 'isActive' => true, 'blocked' => false, 'firstname' => 'John', 'lastname' => 'Doe']]),
        );
        self::assertSame(['title' => ['$contains' => 'est02']], Sanitizers::defaultSanitizeFilters($this->ctx, ['title' => ['$contains' => 'est02']]));
        self::assertSame(['title' => ['$containsi' => 'EST02']], Sanitizers::defaultSanitizeFilters($this->ctx, ['title' => ['$containsi' => 'EST02']]));
        self::assertSame([], Sanitizers::defaultSanitizeFilters($this->ctx, ['title' => ['totallyUnknownNestedKey' => 'x']]));
        self::assertSame(['$or' => [['updatedBy' => ['firstname' => 'John']]]], Sanitizers::defaultSanitizeFilters($this->ctx, ['$or' => [['updatedBy' => ['resetPasswordToken' => ['$startsWith' => 'abc']]], ['updatedBy' => ['firstname' => 'John']]]]));
    }

    public function testKeepsScalarDatetimeGtFilterWithDateOperand(): void
    {
        $schema = TestFixtures::ARTICLE_MODEL;
        $schema['attributes']['publishedAt'] = ['type' => 'datetime'];
        $date = new \DateTimeImmutable('2022-03-17T15:06:57.878Z');
        $filters = ['publishedAt' => ['$gt' => $date]];

        self::assertSame($filters, Sanitizers::defaultSanitizeFilters(['schema' => $schema, 'getModel' => TestFixtures::getModelFn()], $filters));
    }

    public function testTraverseQueryFiltersVisitsNestedOperatorsUnderScalarFields(): void
    {
        $visited = [];
        QueryFilters::traverse(static function (VisitorOptions $o) use (&$visited): void {
            $visited[] = $o->key;
        }, $this->ctx, ['title' => ['$contains' => 'est02']]);

        self::assertContains('title', $visited);
        self::assertContains('$contains', $visited);
    }

    /** @return array{schema: array<string, mixed>, getModel: \Closure} */
    private function populateCtx(): array
    {
        $category = ['uid' => 'api::category.category', 'modelType' => 'contentType', 'kind' => 'collectionType', 'info' => ['singularName' => 'category', 'pluralName' => 'categories', 'displayName' => 'Category'], 'options' => [], 'attributes' => [
            'id' => ['type' => 'integer'], 'name' => ['type' => 'string'],
            'createdBy' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user'],
            'updatedBy' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user'],
        ]];
        $product = ['uid' => 'api::product.product', 'modelType' => 'contentType', 'kind' => 'collectionType', 'info' => ['singularName' => 'product', 'pluralName' => 'products', 'displayName' => 'Product'], 'options' => [], 'attributes' => [
            'id' => ['type' => 'integer'], 'title' => ['type' => 'string'],
            'category' => ['type' => 'relation', 'relation' => 'manyToOne', 'target' => 'api::category.category'],
        ]];
        $models = ['admin::user' => TestFixtures::ADMIN_USER_MODEL, 'api::category.category' => $category, 'api::product.product' => $product];

        return ['schema' => $product, 'getModel' => static fn (string $uid): ?array => $models[$uid] ?? null];
    }

    public function testDefaultSanitizePopulateNestedFiltersSortFields(): void
    {
        $ctx = $this->populateCtx();

        self::assertSame(['category' => ['filters' => []]], Sanitizers::defaultSanitizePopulate($ctx, ['category' => ['filters' => ['createdBy' => ['email' => ['$startsWith' => 'admin']]]]]));
        self::assertSame(['category' => ['sort' => ['createdBy' => null]]], Sanitizers::defaultSanitizePopulate($ctx, ['category' => ['sort' => ['createdBy' => ['resetPasswordToken' => 'asc']]]]));
        self::assertSame(
            ['category' => ['filters' => ['createdBy' => ['firstname' => 'John']], 'sort' => ['updatedBy' => ['lastname' => 'asc']]]],
            Sanitizers::defaultSanitizePopulate($ctx, ['category' => ['filters' => ['createdBy' => ['firstname' => 'John']], 'sort' => ['updatedBy' => ['lastname' => 'asc']]]]),
        );
        self::assertSame(['category' => ['filters' => []]], Sanitizers::defaultSanitizePopulate($ctx, ['category' => ['filters' => ['createdBy' => ['password' => ['$startsWith' => '$2']]]]]));
        self::assertSame(['category' => ['fields' => ['name']]], Sanitizers::defaultSanitizePopulate($ctx, ['category' => ['fields' => ['name', 'title', 'createdBy']]]));
    }

    // ---------------------------------------------------------------------------------------------
    // removeRestrictedRelations
    // ---------------------------------------------------------------------------------------------

    /** Denies `admin::user.find` and allows everything else, like upstream's `strapi.auth.verify` mock. */
    private function denyAdminUser(): void
    {
        AuthScope::setVerifier(static function (mixed $auth, string $scope): bool {
            if ($scope === 'admin::user.find') {
                throw new \RuntimeException('Unauthorized');
            }

            return true;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $attribute
     * @param array<string, mixed> $schema
     * @return array{remove: list<string>, set: list<array{0: string, 1: mixed}>}
     */
    private function runRelationVisitor(RemoveRestrictedRelations $visitor, array $data, string $key, array $attribute, array $schema): array
    {
        $removed = [];
        $set = [];
        $utils = new VisitorUtils($data, static function (string $k, mixed $v, mixed $d) use (&$set): mixed {
            $set[] = [$k, $v];

            return $d;
        }, static function (string $k, mixed $d) use (&$removed): mixed {
            $removed[] = $k;

            return $d;
        });

        $visitor(new VisitorOptions(data: $data, schema: $schema, key: $key, value: [], attribute: $attribute, path: new Path(null, null), getModel: TestFixtures::getModelFn()), $utils);

        return ['remove' => $removed, 'set' => $set];
    }

    public function testKeepsCreatorRelationsWithPopulateCreatorFieldsTrue(): void
    {
        $this->denyAdminUser();
        $visitor = new RemoveRestrictedRelations(new \stdClass());
        $attribute = ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user'];
        $schema = ['kind' => 'collectionType', 'info' => ['singularName' => 'test', 'pluralName' => 'tests'], 'options' => ['populateCreatorFields' => true], 'attributes' => []];

        foreach ([ContentTypes::CREATED_BY_ATTRIBUTE, ContentTypes::UPDATED_BY_ATTRIBUTE] as $key) {
            $result = $this->runRelationVisitor($visitor, [], $key, $attribute, $schema);
            self::assertSame([], $result['remove']);
            self::assertSame([], $result['set']);
        }
    }

    public function testRemovesCreatorRelationsWithPopulateCreatorFieldsFalse(): void
    {
        $this->denyAdminUser();
        $visitor = new RemoveRestrictedRelations(new \stdClass());
        $attribute = ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user'];
        $schema = ['kind' => 'collectionType', 'info' => ['singularName' => 'test', 'pluralName' => 'tests'], 'options' => ['populateCreatorFields' => false], 'attributes' => []];

        foreach ([ContentTypes::CREATED_BY_ATTRIBUTE, ContentTypes::UPDATED_BY_ATTRIBUTE] as $key) {
            $result = $this->runRelationVisitor($visitor, [], $key, $attribute, $schema);
            self::assertSame([$key], $result['remove']);
            self::assertSame([], $result['set']);
        }
    }

    public function testRemovesUnauthorizedMorphToOneRelationTargetsFromOutput(): void
    {
        $this->denyAdminUser();
        $attribute = ['type' => 'relation', 'relation' => 'morphToOne'];
        $schema = ['kind' => 'collectionType', 'info' => ['singularName' => 'test', 'pluralName' => 'tests'], 'options' => [], 'attributes' => ['related' => $attribute]];
        $data = ['related' => ['id' => 1, '__type' => 'admin::user', 'firstname' => 'Private', 'email' => 'private@example.test']];

        $result = $this->runRelationVisitor(new RemoveRestrictedRelations(new \stdClass()), $data, 'related', $attribute, $schema);

        self::assertSame(['related'], $result['remove']);
        self::assertSame([], $result['set']);
    }

    public function testRemovesMorphToManyRelationOutputWhenAllTargetsAreUnauthorized(): void
    {
        $this->denyAdminUser();
        $attribute = ['type' => 'relation', 'relation' => 'morphToMany'];
        $schema = ['kind' => 'collectionType', 'info' => ['singularName' => 'test', 'pluralName' => 'tests'], 'options' => [], 'attributes' => ['related' => $attribute]];
        $data = ['related' => [['id' => 1, '__type' => 'admin::user', 'firstname' => 'Private', 'email' => 'private@example.test']]];

        $result = $this->runRelationVisitor(new RemoveRestrictedRelations(new \stdClass()), $data, 'related', $attribute, $schema);

        self::assertSame(['related'], $result['remove']);
        self::assertSame([], $result['set']);
    }

    public function testKeepsAuthorizedMorphToOneRelationTargetAsAnObject(): void
    {
        $this->denyAdminUser();
        $attribute = ['type' => 'relation', 'relation' => 'morphToOne'];
        $schema = ['kind' => 'collectionType', 'info' => ['singularName' => 'test', 'pluralName' => 'tests'], 'options' => [], 'attributes' => ['related' => $attribute]];
        $related = ['id' => 1, '__type' => 'api::allowed.allowed', 'title' => 'Allowed'];

        $result = $this->runRelationVisitor(new RemoveRestrictedRelations(new \stdClass()), ['related' => $related], 'related', $attribute, $schema);

        self::assertSame([], $result['remove']);
        self::assertSame([['related', $related]], $result['set']);
    }

    public function testKeepsMixedMorphToManyRelationOutputAfterStrippingUnauthorizedTargets(): void
    {
        $this->denyAdminUser();
        $attribute = ['type' => 'relation', 'relation' => 'morphToMany'];
        $schema = ['kind' => 'collectionType', 'info' => ['singularName' => 'test', 'pluralName' => 'tests'], 'options' => [], 'attributes' => ['related' => $attribute]];
        $allowed = ['id' => 1, '__type' => 'api::allowed.allowed', 'title' => 'Allowed'];
        $denied = ['id' => 2, '__type' => 'admin::user', 'firstname' => 'Private'];

        $result = $this->runRelationVisitor(new RemoveRestrictedRelations(new \stdClass()), ['related' => [$allowed, $denied]], 'related', $attribute, $schema);

        self::assertSame([], $result['remove']);
        self::assertSame([['related', [$allowed]]], $result['set']);
    }

    public function testPreservesEmptyMorphToManyRelationOutput(): void
    {
        $this->denyAdminUser();
        $attribute = ['type' => 'relation', 'relation' => 'morphToMany'];
        $schema = ['kind' => 'collectionType', 'info' => ['singularName' => 'test', 'pluralName' => 'tests'], 'options' => [], 'attributes' => ['related' => $attribute]];

        $result = $this->runRelationVisitor(new RemoveRestrictedRelations(new \stdClass()), ['related' => []], 'related', $attribute, $schema);

        self::assertSame([], $result['remove']);
        self::assertSame([], $result['set']);
    }

    public function testSanitizesMorphRelationsConnectSetDisconnectAndOptions(): void
    {
        AuthScope::setVerifier(static fn (mixed $auth, string $scope): bool => $scope !== 'undefined.find');
        $attribute = ['type' => 'relation', 'relation' => 'morphToMany', 'target' => 'admin::user'];
        $schema = ['kind' => 'collectionType', 'info' => ['singularName' => 'test', 'pluralName' => 'tests'], 'options' => ['populateCreatorFields' => true], 'attributes' => ['morph' => $attribute]];
        $data = ['morph' => [
            'connect' => [['__type' => 'api::admin:morphtest', 'id' => 1], 'invalid'],
            'set' => [['__type' => 'api::admin:morphtest', 'id' => 1], 'invalid'],
            'disconnect' => [['__type' => 'api::admin:morphtest', 'id' => 1], 'invalid'],
            'options' => ['strict' => false, 'fakeOption' => ['fake' => 'string']],
            'fakeProp' => 'asdf',
        ]];

        $result = $this->runRelationVisitor(new RemoveRestrictedRelations(new \stdClass()), $data, 'morph', $attribute, $schema);

        self::assertSame([], $result['remove']);
        self::assertSame([['morph', [
            'connect' => [['__type' => 'api::admin:morphtest', 'id' => 1]],
            'set' => [['__type' => 'api::admin:morphtest', 'id' => 1]],
            'disconnect' => [['__type' => 'api::admin:morphtest', 'id' => 1]],
            'options' => ['strict' => false],
        ]]], $result['set']);
    }

    // ---------------------------------------------------------------------------------------------
    // scope decisions across a list of entities
    // ---------------------------------------------------------------------------------------------

    /** @return array{schema: array<string, mixed>, getModel: \Closure} */
    private function restrictedCtx(): array
    {
        $schema = ['kind' => 'collectionType', 'info' => ['singularName' => 'article', 'pluralName' => 'articles'], 'options' => [], 'attributes' => [
            'title' => ['type' => 'string'],
            'secretRelation' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::secret.secret'],
        ]];
        $secret = ['kind' => 'collectionType', 'info' => ['singularName' => 'secret', 'pluralName' => 'secrets'], 'attributes' => ['name' => ['type' => 'string']]];

        return ['schema' => $schema, 'getModel' => static fn (string $uid): ?array => $uid === 'api::secret.secret' ? $secret : TestFixtures::getModel($uid)];
    }

    /** @return list<array<string, mixed>> */
    private static function makePage(int $count): array
    {
        return array_map(static fn (int $i): array => ['title' => "entity-{$i}", 'secretRelation' => ['name' => "secret-{$i}"]], range(0, $count - 1));
    }

    public function testStripsTheRestrictedRelationFromEveryEntityInThePage(): void
    {
        $calls = 0;
        AuthScope::setVerifier(static function () use (&$calls): bool {
            $calls++;
            throw new \RuntimeException('Forbidden');
        });
        $auth = (object) ['strategy' => 'api-token', 'credentials' => null];
        $visitor = new RemoveRestrictedRelations($auth);

        $sanitized = array_map(fn (array $entity): mixed => TraverseEntity::traverse($visitor, $this->restrictedCtx(), $entity), self::makePage(4));

        foreach ($sanitized as $i => $entity) {
            self::assertArrayNotHasKey('secretRelation', $entity);
            self::assertSame("entity-{$i}", $entity['title']);
        }
        // the scope was resolved once and reused for the other entities
        self::assertSame(1, $calls);
    }

    public function testKeepsTheRelationOnEveryEntityWhenTheScopeIsAllowed(): void
    {
        AuthScope::setVerifier(static fn (): bool => true);
        $visitor = new RemoveRestrictedRelations((object) ['strategy' => 'api-token']);

        foreach (self::makePage(3) as $i => $entity) {
            $sanitized = TraverseEntity::traverse($visitor, $this->restrictedCtx(), $entity);
            self::assertArrayHasKey('secretRelation', $sanitized);
            self::assertSame("entity-{$i}", $sanitized['title']);
        }
    }

    public function testAbilityCallableOnAuthDecidesScopes(): void
    {
        $auth = ['ability' => static fn (string $action): bool => $action !== 'api::secret.secret.find'];
        $sanitized = TraverseEntity::traverse(new RemoveRestrictedRelations($auth), $this->restrictedCtx(), self::makePage(1)[0]);

        self::assertArrayNotHasKey('secretRelation', $sanitized);
    }

    // ---------------------------------------------------------------------------------------------
    // removeUnrecognizedFields
    // ---------------------------------------------------------------------------------------------

    public function testRemovesUnrecognizedFieldsAtRootLevel(): void
    {
        $traverse = TraverseEntity::create(new RemoveUnrecognizedFields(), $this->ctx);

        self::assertSame(['title' => 'Test Article'], $traverse(['title' => 'Test Article', 'unrecognizedField' => 'should be removed']));
        self::assertSame(['title' => 'Test Article'], $traverse(['title' => 'Test Article']));
        self::assertSame(['title' => 'Test Article'], $traverse(['title' => 'Test Article', 'unrecognizedField1' => 'x', 'unrecognizedField2' => 'y']));
        self::assertSame(['id' => 1, 'documentId' => 'abc'], $traverse(['id' => 1, 'documentId' => 'abc', 'nope' => 1]));
    }

    public function testAllowsSpecialRelationReorderingFields(): void
    {
        $relationModel = ['uid' => 'api::relation.relation', 'modelType' => 'contentType', 'kind' => 'collectionType', 'info' => ['singularName' => 'relation', 'pluralName' => 'relations'], 'options' => [], 'attributes' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]];
        $article = TestFixtures::ARTICLE_MODEL;
        $article['attributes']['relation'] = ['type' => 'relation', 'relation' => 'manyToMany', 'target' => 'api::relation.relation'];
        $ctx = ['schema' => $article, 'getModel' => static fn (string $uid): ?array => $uid === 'api::relation.relation' ? $relationModel : TestFixtures::getModel($uid)];

        $result = TraverseEntity::traverse(new RemoveUnrecognizedFields(), $ctx, ['title' => 'Test Article', 'relation' => ['connect' => [['id' => 1]], 'unrecognizedField' => 'should be removed']]);

        self::assertSame(['title' => 'Test Article', 'relation' => ['connect' => [['id' => 1]]]], $result);
    }

    public function testKeepsIdFieldsInRelationComponentAndMediaContext(): void
    {
        $relationModel = ['uid' => 'api::relation.relation', 'modelType' => 'contentType', 'kind' => 'collectionType', 'attributes' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]];
        $componentModel = ['uid' => 'default.component', 'modelType' => 'component', 'attributes' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]];
        $mediaModel = ['uid' => 'plugin::upload.file', 'modelType' => 'contentType', 'kind' => 'collectionType', 'attributes' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'url' => ['type' => 'string']]];
        $article = TestFixtures::ARTICLE_MODEL;
        $article['attributes']['relation'] = ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::relation.relation'];
        $article['attributes']['component'] = ['type' => 'component', 'component' => 'default.component'];
        $article['attributes']['image'] = ['type' => 'media', 'allowedTypes' => ['images']];
        $models = ['api::relation.relation' => $relationModel, 'default.component' => $componentModel, 'plugin::upload.file' => $mediaModel];
        $ctx = ['schema' => $article, 'getModel' => static fn (string $uid): ?array => $models[$uid] ?? TestFixtures::getModel($uid)];
        $traverse = TraverseEntity::create(new RemoveUnrecognizedFields(), $ctx);

        $data = ['title' => 'Test Article', 'relation' => ['id' => 1], 'component' => ['id' => 1, 'name' => 'Component Name'], 'image' => ['id' => 1]];
        self::assertSame($data, $traverse($data));
    }
}
