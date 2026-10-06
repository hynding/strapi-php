<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\AuthScope;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Traverse\Path;
use Strapi\Utils\Traverse\QueryFilters;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\TraverseEntity;
use Strapi\Utils\Validate\Utils;
use Strapi\Utils\Validate\Validators;
use Strapi\Utils\Validate\Visitors\ThrowPassword;
use Strapi\Utils\Validate\Visitors\ThrowPrivate;
use Strapi\Utils\Validate\Visitors\ThrowRestrictedRelations;
use Strapi\Utils\Validate\Visitors\ThrowUnrecognizedFields;

/** Port of __tests__/validate.visitors.test.ts, validate-utils.vitest.test.ts and morph.test.ts (validate half). */
final class ValidateVisitorsTest extends TestCase
{
    /** @var array{schema: array<string, mixed>, getModel: \Closure} */
    private array $ctx;

    protected function setUp(): void
    {
        $this->ctx = ['schema' => TestFixtures::ARTICLE_MODEL, 'getModel' => TestFixtures::getModelFn()];
        AuthScope::setVerifier(null);
    }

    protected function tearDown(): void
    {
        AuthScope::setVerifier(null);
    }

    public function testThrowInvalidKey(): void
    {
        try {
            Utils::throwInvalidKey(['key' => 'filters']);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame('Invalid key filters', $e->getMessage());
        }

        try {
            Utils::throwInvalidKey(['key' => 'name', 'path' => 'filters.name']);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame('Invalid key name at filters.name', $e->getMessage());
            self::assertSame(['key' => 'name', 'path' => 'filters.name'], $e->details);
        }
    }

    public function testAsyncCurry(): void
    {
        $add = static fn (int $a, int $b, int $c): int => $a + $b + $c;
        $curried = Utils::asyncCurry($add);

        self::assertSame(6, $curried(1)(2)(3));
        self::assertSame(6, $curried(1, 2)(3));
        self::assertSame(6, $curried(1, 2, 3));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function privateFilters(): iterable
    {
        yield 'resetPasswordToken' => [['updatedBy' => ['resetPasswordToken' => ['$startsWith' => 'abc']]]];
        yield 'email' => [['updatedBy' => ['email' => ['$contains' => 'admin@']]]];
        yield 'isActive' => [['createdBy' => ['isActive' => true]]];
    }

    /** @param array<string, mixed> $filters */
    #[DataProvider('privateFilters')]
    public function testThrowPrivateBlocksPrivateFieldsInRelationalFilters(array $filters): void
    {
        $this->expectException(ValidationError::class);
        QueryFilters::traverse(new ThrowPrivate(), $this->ctx, $filters);
    }

    public function testThrowPrivateAllowsPublicFields(): void
    {
        $filters = ['updatedBy' => ['firstname' => 'John']];
        self::assertSame($filters, QueryFilters::traverse(new ThrowPrivate(), $this->ctx, $filters));
    }

    public function testThrowPasswordBlocksPasswordFields(): void
    {
        $this->expectException(ValidationError::class);
        QueryFilters::traverse(new ThrowPassword(), $this->ctx, ['createdBy' => ['password' => ['$startsWith' => '$2b$']]]);
    }

    public function testValidateFiltersIncludes(): void
    {
        $private = ['updatedBy' => ['resetPasswordToken' => ['$startsWith' => 'abc']]];
        $password = ['createdBy' => ['password' => ['$startsWith' => '$2b$']]];

        self::assertSame($private, Validators::validateFilters($this->ctx, $private, ['nonAttributesOperators', 'passwords']));
        // the fixture flags `password` as private too, so only the password traversal is relevant here
        self::assertSame($password, Validators::validateFilters($this->ctx, $password, ['nonAttributesOperators']));
        self::assertSame($private, Validators::validateFilters($this->ctx, $private, []));

        try {
            Validators::validateFilters($this->ctx, $private, ['private']);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame('Invalid key resetPasswordToken at updatedBy.resetPasswordToken', $e->getMessage());
        }

        $this->expectException(ValidationError::class);
        Validators::validateFilters($this->ctx, $password, ['passwords']);
    }

    public function testThrowsWhenScalarFieldFilterMapContainsAnInvalidNestedKey(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key totallyUnknownNestedKey at title');
        Validators::defaultValidateFilters($this->ctx, ['title' => ['totallyUnknownNestedKey' => 'x']]);
    }

    public function testValidateFiltersAcceptsOpaqueOperands(): void
    {
        $schema = TestFixtures::ARTICLE_MODEL;
        $schema['attributes']['publishedAt'] = ['type' => 'datetime'];
        $ctx = ['schema' => $schema, 'getModel' => TestFixtures::getModelFn()];
        $filters = ['publishedAt' => ['$gt' => new \DateTimeImmutable()], 'title' => ['$null' => true], '$and' => [['title' => ['$in' => ['a', 'b']]]]];

        self::assertEquals($filters, Validators::defaultValidateFilters($ctx, $filters));
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

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidNestedPopulates(): iterable
    {
        yield 'private field in nested filters' => [['category' => ['filters' => ['createdBy' => ['email' => ['$startsWith' => 'admin']]]]]];
        yield 'private field in nested sort' => [['category' => ['sort' => ['createdBy' => ['resetPasswordToken' => 'asc']]]]];
        yield 'password field in nested filters' => [['category' => ['filters' => ['createdBy' => ['password' => ['$startsWith' => '$2']]]]]];
        yield 'non populatable attribute' => [['title' => true]];
        yield 'unknown fragment target' => [['category' => ['populate' => ['createdBy' => ['on' => ['api::nope.nope' => true]]]]]];
    }

    /** @param array<string, mixed> $populate */
    #[DataProvider('invalidNestedPopulates')]
    public function testDefaultValidatePopulateThrows(array $populate): void
    {
        $this->expectException(ValidationError::class);
        Validators::defaultValidatePopulate($this->populateCtx(), $populate);
    }

    /** @return iterable<string, array{mixed}> */
    public static function meaninglessSorts(): iterable
    {
        yield 'empty sort array' => [[]];
        yield 'qs empty array (sort[])' => [[null]];
        yield 'empty sort string' => [''];
        yield 'comma-only sort string' => [','];
        yield 'array of empty strings' => [['']];
    }

    #[DataProvider('meaninglessSorts')]
    public function testAcceptsNestedPopulateSortWithNoMeaningfulOrder(mixed $sortValue): void
    {
        $populate = ['category' => ['sort' => $sortValue]];
        self::assertIsArray(Validators::defaultValidatePopulate($this->populateCtx(), $populate));
    }

    public function testAllowsPublicFieldsInNestedFiltersSortWithinPopulate(): void
    {
        $populate = ['category' => ['filters' => ['createdBy' => ['firstname' => 'John']], 'sort' => ['updatedBy' => ['lastname' => 'asc']], 'fields' => ['name'], 'count' => 'true', 'populate' => ['createdBy' => true]]];
        self::assertSame($populate, Validators::defaultValidatePopulate($this->populateCtx(), $populate));
    }

    /** @return iterable<array{string, string}> */
    public static function rootOnlyParams(): iterable
    {
        yield ['status', 'draft'];
        yield ['publicationFilter', 'never-published'];
        yield ['hasPublishedVersion', 'false'];
    }

    #[DataProvider('rootOnlyParams')]
    public function testExplainsThatParamIsRootOnlyInsideANestedPopulate(string $key, string $value): void
    {
        try {
            Validators::defaultValidatePopulate($this->populateCtx(), ['category' => [$key => $value]]);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertSame("Invalid key {$key} at category: {$key} is only accepted at the root of the query, and it also applies to populated relations", $e->getMessage());
            self::assertSame(['key' => $key, 'path' => 'category'], $e->details);
        }
    }

    public function testKeepsThePlainMessageForOtherUnrecognizedKeysInsideANestedPopulate(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key unknownOption at category');
        Validators::defaultValidatePopulate($this->populateCtx(), ['category' => ['unknownOption' => true]]);
    }

    /** @return array{schema: array<string, mixed>, getModel: \Closure} */
    private function dynamicZoneCtx(): array
    {
        $model = ['uid' => 'api::article.article', 'modelType' => 'contentType', 'kind' => 'collectionType', 'info' => ['singularName' => 'article', 'pluralName' => 'articles', 'displayName' => 'Article'], 'options' => [], 'attributes' => [
            'id' => ['type' => 'integer'],
            'contentBlocks' => ['type' => 'dynamiczone', 'components' => ['default.component']],
            'morphLink' => ['type' => 'relation', 'relation' => 'morphToOne'],
        ]];
        $component = ['uid' => 'default.component', 'modelType' => 'component', 'info' => ['singularName' => 'component', 'pluralName' => 'components'], 'options' => [], 'attributes' => ['title' => ['type' => 'string']]];
        $models = ['api::article.article' => $model, 'default.component' => $component];

        return ['schema' => $model, 'getModel' => static fn (string $uid): ?array => $models[$uid] ?? null];
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidPolymorphicPopulates(): iterable
    {
        foreach (['contentBlocks', 'morphLink'] as $key) {
            yield "{$key} string" => [$key, 'deep'];
            yield "{$key} object" => [$key, ['title' => true]];
        }
    }

    #[DataProvider('invalidPolymorphicPopulates')]
    public function testThrowsForInvalidPopulateInPolymorphicStructures(string $key, mixed $populateValue): void
    {
        $this->expectException(ValidationError::class);
        Validators::defaultValidatePopulate($this->dynamicZoneCtx(), [$key => ['populate' => $populateValue]]);
    }

    public function testAllowsNilAndWildcardPolymorphicNestedPopulateValue(): void
    {
        self::assertIsArray(Validators::defaultValidatePopulate($this->dynamicZoneCtx(), ['contentBlocks' => ['populate' => null]]));
        self::assertIsArray(Validators::defaultValidatePopulate($this->dynamicZoneCtx(), ['contentBlocks' => ['populate' => '*']]));
        self::assertIsArray(Validators::defaultValidatePopulate($this->dynamicZoneCtx(), ['contentBlocks' => ['on' => ['default.component' => ['fields' => ['title']]]]]));
        self::assertIsArray(Validators::defaultValidatePopulate($this->dynamicZoneCtx(), ['morphLink' => true]));
        self::assertIsArray(Validators::defaultValidatePopulate($this->dynamicZoneCtx(), ['morphLink' => ['count' => true]]));
    }

    /** @return array{schema: array<string, mixed>, getModel: \Closure} */
    private function withDynamicZonePopulateCtx(): array
    {
        $model = ['uid' => 'api::withdynamiczone.withdynamiczone', 'modelType' => 'contentType', 'kind' => 'collectionType', 'info' => ['singularName' => 'withdynamiczone', 'pluralName' => 'withdynamiczones'], 'options' => [], 'attributes' => [
            'id' => ['type' => 'integer'],
            'field' => ['type' => 'dynamiczone', 'components' => ['default.compo-with-other-compo', 'default.simple-compo']],
        ]];
        $models = [
            'api::withdynamiczone.withdynamiczone' => $model,
            'default.compo-with-other-compo' => ['uid' => 'default.compo-with-other-compo', 'modelType' => 'component', 'options' => [], 'attributes' => ['compo' => ['type' => 'component', 'component' => 'default.simple-compo']]],
            'default.simple-compo' => ['uid' => 'default.simple-compo', 'modelType' => 'component', 'options' => [], 'attributes' => ['name' => ['type' => 'string']]],
        ];

        return ['schema' => $model, 'getModel' => static fn (string $uid): ?array => $models[$uid] ?? null];
    }

    /** @return iterable<string, array{mixed}> */
    public static function validDynamicZonePopulates(): iterable
    {
        yield 'dot-notation nested component path' => [['field.compo']];
        yield 'dot-notation shallow dynamic zone' => [['field']];
        yield 'wildcard nested populate object' => [['field' => ['populate' => '*']]];
        yield 'populate array mixing shallow and nested paths' => [['field', 'field.compo']];
        yield 'comma separated string' => ['field,field.compo'];
    }

    #[DataProvider('validDynamicZonePopulates')]
    public function testAllowsValidDynamicZonePopulate(mixed $populate): void
    {
        self::assertNotNull(Validators::defaultValidatePopulate($this->withDynamicZonePopulateCtx(), $populate));
    }

    public function testRejectsUnknownDotNotationPaths(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key nope at field.nope');
        Validators::defaultValidatePopulate($this->withDynamicZonePopulateCtx(), ['field.nope']);
    }

    public function testRejectsDotNotationOnScalars(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key title');
        Validators::defaultValidatePopulate($this->ctx, 'title');
    }

    public function testAllowsDotNotationShallowPopulateOnMorphToOne(): void
    {
        $host = ['uid' => 'api::host.host', 'modelType' => 'contentType', 'kind' => 'collectionType', 'options' => [], 'attributes' => ['id' => ['type' => 'integer'], 'morphLink' => ['type' => 'relation', 'relation' => 'morphToOne', 'target' => 'api::target.target']]];
        $target = ['uid' => 'api::target.target', 'modelType' => 'contentType', 'kind' => 'collectionType', 'attributes' => ['id' => ['type' => 'integer']]];
        $ctx = ['schema' => $host, 'getModel' => static fn (string $uid): array => $uid === 'api::host.host' ? $host : $target];

        self::assertSame(['morphLink'], Validators::defaultValidatePopulate($ctx, ['morphLink']));
    }

    public function testQsArrayLimitPopulateObjectThrowsAClearError(): void
    {
        $schema = ['kind' => 'collectionType', 'attributes' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']]];
        $ctx = ['schema' => $schema, 'getModel' => static fn (): array => $schema];
        $populate = array_map(static fn (int $i): string => "field{$i}", range(0, 100));

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Too many populate entries (101). The maximum number of populate entries when using array notation is 100.');
        Validators::defaultValidatePopulate($ctx, $populate);
    }

    // ---------------------------------------------------------------------------------------------
    // throwRestrictedRelations
    // ---------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $attribute
     * @param array<string, mixed> $schema
     */
    private function runRelationVisitor(ThrowRestrictedRelations $visitor, array $data, string $key, array $attribute, array $schema): void
    {
        $visitor(new VisitorOptions(data: $data, schema: $schema, key: $key, value: [], attribute: $attribute, path: new Path(null, null), getModel: TestFixtures::getModelFn()), new VisitorUtils($data));
    }

    public function testKeepsCreatorRelationsWithPopulateCreatorFieldsTrue(): void
    {
        AuthScope::setVerifier(static fn (mixed $auth, string $scope): bool => $scope !== 'admin::user.find');
        $attribute = ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user'];
        $schema = ['kind' => 'collectionType', 'options' => ['populateCreatorFields' => true], 'attributes' => []];

        foreach ([ContentTypes::CREATED_BY_ATTRIBUTE, ContentTypes::UPDATED_BY_ATTRIBUTE] as $key) {
            $this->runRelationVisitor(new ThrowRestrictedRelations(new \stdClass()), [], $key, $attribute, $schema);
        }
        self::assertTrue(true);
    }

    public function testThrowsOnCreatorRelationsWithPopulateCreatorFieldsFalse(): void
    {
        AuthScope::setVerifier(static fn (mixed $auth, string $scope): bool => $scope !== 'admin::user.find');
        $attribute = ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user'];
        $schema = ['kind' => 'collectionType', 'options' => ['populateCreatorFields' => false], 'attributes' => []];

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key createdBy');
        $this->runRelationVisitor(new ThrowRestrictedRelations(new \stdClass()), [], ContentTypes::CREATED_BY_ATTRIBUTE, $attribute, $schema);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private static function morphFixture(): array
    {
        $attribute = ['type' => 'relation', 'relation' => 'morphToMany', 'target' => 'admin::user'];
        $schema = ['kind' => 'collectionType', 'info' => ['singularName' => 'test', 'pluralName' => 'tests'], 'options' => ['populateCreatorFields' => true], 'attributes' => ['morph' => $attribute]];

        return [$attribute, $schema];
    }

    public function testValidatesValidMorphRelationsConnectSetDisconnectAndOptions(): void
    {
        AuthScope::setVerifier(static fn (mixed $auth, string $scope): bool => $scope !== 'undefined.find');
        [$attribute, $schema] = self::morphFixture();
        $element = ['__type' => 'api::admin:morphtest', 'id' => 1];

        $this->runRelationVisitor(new ThrowRestrictedRelations(new \stdClass()), ['morph' => ['connect' => [$element], 'set' => [$element], 'disconnect' => [$element], 'options' => ['strict' => false]]], 'morph', $attribute, $schema);
        self::assertTrue(true);
    }

    public function testThrowsForInvalidMorphRelationsOption(): void
    {
        AuthScope::setVerifier(static fn (): bool => true);
        [$attribute, $schema] = self::morphFixture();
        $element = ['__type' => 'api::admin:morphtest', 'id' => 1];

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key invalidoption');
        $this->runRelationVisitor(new ThrowRestrictedRelations(new \stdClass()), ['morph' => ['connect' => [$element], 'set' => [$element], 'disconnect' => [$element], 'options' => ['invalidoption' => true]]], 'morph', $attribute, $schema);
    }

    /** @return iterable<array{string}> */
    public static function morphOperations(): iterable
    {
        yield ['connect'];
        yield ['set'];
        yield ['disconnect'];
    }

    #[DataProvider('morphOperations')]
    public function testThrowsForInvalidMorphRelationsElements(string $operation): void
    {
        AuthScope::setVerifier(static fn (): bool => true);
        [$attribute, $schema] = self::morphFixture();
        $element = ['__type' => 'api::admin:morphtest', 'id' => 1];
        $payload = ['connect' => [$element], 'set' => [$element], 'disconnect' => [$element], 'options' => []];
        $payload[$operation] = ['invalidString'];

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key morph');
        $this->runRelationVisitor(new ThrowRestrictedRelations(new \stdClass()), ['morph' => $payload], 'morph', $attribute, $schema);
    }

    // ---------------------------------------------------------------------------------------------
    // throwUnrecognizedFields
    // ---------------------------------------------------------------------------------------------

    public function testThrowsForUnrecognizedFieldAtRootLevel(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid key unrecognizedField');
        TraverseEntity::traverse(new ThrowUnrecognizedFields(), $this->ctx, ['title' => 'Test Article', 'unrecognizedField' => 'should throw']);
    }

    public function testAllowsRecognizedFields(): void
    {
        $data = ['title' => 'Test Article', 'id' => 1, 'documentId' => 'abc'];
        self::assertSame($data, TraverseEntity::traverse(new ThrowUnrecognizedFields(), $this->ctx, $data));
        self::assertSame(['extra' => 1], TraverseEntity::traverse(new ThrowUnrecognizedFields(), [...$this->ctx, 'allowedExtraRootKeys' => ['extra']], ['extra' => 1]));
    }

    public function testThrowsForUnrecognizedFieldInRelationObjectWithReordering(): void
    {
        $relationModel = ['uid' => 'api::relation.relation', 'modelType' => 'contentType', 'kind' => 'collectionType', 'attributes' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]];
        $article = TestFixtures::ARTICLE_MODEL;
        $article['attributes']['relation'] = ['type' => 'relation', 'relation' => 'manyToMany', 'target' => 'api::relation.relation'];
        $ctx = ['schema' => $article, 'getModel' => static fn (string $uid): ?array => $uid === 'api::relation.relation' ? $relationModel : TestFixtures::getModel($uid)];

        $this->expectException(ValidationError::class);
        TraverseEntity::traverse(new ThrowUnrecognizedFields(), $ctx, ['title' => 'Test Article', 'relation' => ['connect' => [['id' => 1]], 'unrecognizedField' => 'x']]);
    }

    public function testIncludesPathInformationInErrorMessageForInvalidField(): void
    {
        $component = ['uid' => 'default.component', 'modelType' => 'component', 'options' => [], 'attributes' => ['name' => ['type' => 'string']]];
        $article = TestFixtures::ARTICLE_MODEL;
        $article['attributes']['components'] = ['type' => 'component', 'component' => 'default.component', 'repeatable' => true];
        $ctx = ['schema' => $article, 'getModel' => static fn (string $uid): ?array => $uid === 'default.component' ? $component : TestFixtures::getModel($uid)];

        try {
            TraverseEntity::traverse(new ThrowUnrecognizedFields(), $ctx, ['title' => 'Test Article', 'components' => [['name' => 'Component 1', 'invalidField' => 'x']]]);
            self::fail('expected throw');
        } catch (ValidationError $e) {
            self::assertMatchesRegularExpression('/Invalid key invalidField at components/', $e->getMessage());
            self::assertSame('invalidField', $e->details['key']);
            self::assertSame('components', $e->details['path']);
        }
    }

    public function testAllowsIdFieldsAndRelationOperationsInNestedContexts(): void
    {
        $relationModel = ['uid' => 'api::relation.relation', 'modelType' => 'contentType', 'kind' => 'collectionType', 'attributes' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]];
        $componentModel = ['uid' => 'default.component', 'modelType' => 'component', 'attributes' => ['name' => ['type' => 'string']]];
        $mediaModel = ['uid' => 'plugin::upload.file', 'modelType' => 'contentType', 'kind' => 'collectionType', 'attributes' => ['name' => ['type' => 'string']]];
        $article = TestFixtures::ARTICLE_MODEL;
        $article['attributes']['relation'] = ['type' => 'relation', 'relation' => 'manyToMany', 'target' => 'api::relation.relation'];
        $article['attributes']['component'] = ['type' => 'component', 'component' => 'default.component'];
        $article['attributes']['components'] = ['type' => 'component', 'component' => 'default.component', 'repeatable' => true];
        $article['attributes']['image'] = ['type' => 'media'];
        $article['attributes']['images'] = ['type' => 'media', 'multiple' => true];
        $article['attributes']['dz'] = ['type' => 'dynamiczone', 'components' => ['default.component']];
        $models = ['api::relation.relation' => $relationModel, 'default.component' => $componentModel, 'plugin::upload.file' => $mediaModel];
        $ctx = ['schema' => $article, 'getModel' => static fn (string $uid): ?array => $models[$uid] ?? TestFixtures::getModel($uid)];

        $data = [
            'title' => 'Test Article',
            'relation' => ['connect' => [['id' => 1], ['documentId' => 'abc']], 'set' => [['id' => 2]], 'options' => ['strict' => true]],
            'component' => ['id' => 1, 'name' => 'Component Name'],
            'components' => [['id' => 1, 'name' => 'a'], ['name' => 'b']],
            'image' => ['id' => 1],
            'images' => [['id' => 1], ['documentId' => 'x']],
            'dz' => [['__component' => 'default.component', 'name' => 'c']],
        ];

        self::assertSame($data, TraverseEntity::traverse(new ThrowUnrecognizedFields(), $ctx, $data));
    }
}
