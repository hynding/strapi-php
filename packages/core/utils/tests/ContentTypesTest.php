<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/** Port of __tests__/content-types.test.ts plus the Schema-object acceptance. */
final class ContentTypesTest extends TestCase
{
    protected function tearDown(): void
    {
        ContentTypes::setGlobalPrivateAttributes([]);
    }

    /**
     * @param list<string> $privateAttributes
     * @return array<string, mixed>
     */
    private static function modelWithPrivates(array $privateAttributes = []): array
    {
        return ['uid' => 'myModel', 'options' => ['privateAttributes' => $privateAttributes], 'attributes' => [
            'foo' => ['type' => 'string', 'private' => true],
            'bar' => ['type' => 'number', 'private' => false],
            'foobar' => ['type' => 'string'],
        ]];
    }

    public function testConstantsExist(): void
    {
        self::assertSame('createdBy', ContentTypes::CONSTANTS['CREATED_BY_ATTRIBUTE']);
        self::assertSame('updatedBy', ContentTypes::CONSTANTS['UPDATED_BY_ATTRIBUTE']);
        self::assertSame('publishedAt', ContentTypes::CONSTANTS['PUBLISHED_AT_ATTRIBUTE']);
        self::assertSame(['live', 'preview'], ContentTypes::DP_PUB_STATES);
    }

    public function testGetNonWritableAttributesIncludesNonWritableFields(): void
    {
        $model = ['attributes' => ['title' => ['type' => 'string'], 'non_writable_field' => ['type' => 'string', 'writable' => false], 'createdAt' => ['type' => 'datetime'], 'updatedAt' => ['type' => 'datetime']]];

        self::assertSame(['id', 'documentId', 'createdAt', 'updatedAt', 'non_writable_field'], ContentTypes::getNonWritableAttributes($model));
        self::assertSame(['title'], ContentTypes::getWritableAttributes($model));
        self::assertTrue(ContentTypes::isWritableAttribute($model, 'title'));
        self::assertFalse(ContentTypes::isWritableAttribute($model, 'createdAt'));
        self::assertSame([], ContentTypes::getNonWritableAttributes(null));
    }

    public function testGetVisibleAttributes(): void
    {
        self::assertSame(['title'], ContentTypes::getVisibleAttributes(['attributes' => ['title' => ['type' => 'string'], 'invisible_field' => ['type' => 'datetime', 'visible' => false]]]));
        self::assertSame(['title'], ContentTypes::getVisibleAttributes(['attributes' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string']]]));
        self::assertTrue(ContentTypes::isVisibleAttribute(['attributes' => ['title' => ['type' => 'string']]], 'title'));
    }

    public function testGetDoesAttributeRequireValidation(): void
    {
        self::assertFalse(ContentTypes::getDoesAttributeRequireValidation(['type' => 'string']));
        self::assertTrue(ContentTypes::getDoesAttributeRequireValidation(['type' => 'string', 'required' => true]));
        self::assertTrue(ContentTypes::getDoesAttributeRequireValidation(['type' => 'string', 'unique' => true]));
        self::assertTrue(ContentTypes::getDoesAttributeRequireValidation(['type' => 'integer', 'max' => 1]));
        self::assertTrue(ContentTypes::getDoesAttributeRequireValidation(['type' => 'integer', 'min' => 1]));
        self::assertTrue(ContentTypes::getDoesAttributeRequireValidation(['type' => 'string', 'maxLength' => 1]));
        self::assertTrue(ContentTypes::getDoesAttributeRequireValidation(['type' => 'string', 'minLength' => 1]));
        self::assertFalse(ContentTypes::getDoesAttributeRequireValidation(['type' => 'string', 'required' => false]));
        self::assertFalse(ContentTypes::getDoesAttributeRequireValidation(['type' => 'string', 'unique' => false]));
    }

    public function testGetPrivateAttributes(): void
    {
        self::assertSame(['foo'], ContentTypes::getPrivateAttributes(self::modelWithPrivates()));

        ContentTypes::setGlobalPrivateAttributes(['bar']);
        self::assertSame(['bar', 'foo'], ContentTypes::getPrivateAttributes(self::modelWithPrivates()));
        ContentTypes::setGlobalPrivateAttributes([]);

        self::assertSame(['bar', 'foo'], ContentTypes::getPrivateAttributes(self::modelWithPrivates(['bar'])));
    }

    public function testIsPrivateAttribute(): void
    {
        $model = self::modelWithPrivates();
        self::assertTrue(ContentTypes::isPrivateAttribute($model, 'foo'));
        self::assertFalse(ContentTypes::isPrivateAttribute($model, 'bar'));
        self::assertFalse(ContentTypes::isPrivateAttribute($model, 'foobar'));

        ContentTypes::setGlobalPrivateAttributes(['bar']);
        self::assertTrue(ContentTypes::isPrivateAttribute($model, 'bar'));
        ContentTypes::setGlobalPrivateAttributes([]);

        self::assertTrue(ContentTypes::isPrivateAttribute(self::modelWithPrivates(['bar']), 'bar'));
        self::assertFalse(ContentTypes::isPrivateAttribute(null, 'bar'));
    }

    public function testIsTypedAttribute(): void
    {
        self::assertFalse(ContentTypes::isTypedAttribute([], 'string'));
        self::assertTrue(ContentTypes::isTypedAttribute(['type' => 'string'], 'string'));
        self::assertFalse(ContentTypes::isTypedAttribute(['type' => 'number'], 'string'));
    }

    public function testAttributeTypePredicates(): void
    {
        self::assertTrue(ContentTypes::isScalarAttribute(['type' => 'string']));
        self::assertFalse(ContentTypes::isScalarAttribute(['type' => 'relation']));
        self::assertFalse(ContentTypes::isScalarAttribute(null));
        self::assertTrue(ContentTypes::isMediaAttribute(['type' => 'media']));
        self::assertTrue(ContentTypes::isRelationalAttribute(['type' => 'relation', 'relation' => 'oneToOne']));
        self::assertTrue(ContentTypes::isComponentAttribute(['type' => 'dynamiczone']));
        self::assertTrue(ContentTypes::isDynamicZoneAttribute(['type' => 'dynamiczone']));
        self::assertTrue(ContentTypes::isMorphToRelationalAttribute(['type' => 'relation', 'relation' => 'morphToMany']));
        self::assertFalse(ContentTypes::isMorphToRelationalAttribute(['type' => 'relation', 'relation' => 'morphMany']));
        self::assertTrue(ContentTypes::isMorphRelationalAttribute(['type' => 'relation', 'relation' => 'morphMany']));
        self::assertTrue(ContentTypes::hasRelationReordering(['type' => 'relation', 'relation' => 'manyToMany']));
        self::assertFalse(ContentTypes::hasRelationReordering(['type' => 'relation', 'relation' => 'oneToOne']));
    }

    public function testGetScalarAttributesAndFriends(): void
    {
        $model = ['attributes' => [
            'title' => ['type' => 'string'], 'count' => ['type' => 'integer'], 'media' => ['type' => 'media'],
            'relation' => ['type' => 'relation', 'relation' => 'oneToOne'], 'compo' => ['type' => 'component'], 'dz' => ['type' => 'dynamiczone'],
        ]];
        self::assertSame(['title', 'count'], ContentTypes::getScalarAttributes($model));
        self::assertSame(['media'], ContentTypes::getMediaAttributes($model));
        self::assertSame(['relation'], ContentTypes::getRelationalAttributes($model));
        self::assertSame(['compo', 'dz'], ContentTypes::getComponentAttributes($model));
    }

    public function testTimestampsCreatorFieldsAndOptions(): void
    {
        $model = ['attributes' => ['createdAt' => ['type' => 'datetime'], 'createdBy' => ['type' => 'relation']], 'options' => ['draftAndPublish' => true]];
        self::assertSame(['createdAt'], ContentTypes::getTimestamps($model));
        self::assertSame(['createdBy'], ContentTypes::getCreatorFields($model));
        self::assertSame(['draftAndPublish' => true], ContentTypes::getOptions($model));
        self::assertSame(['draftAndPublish' => false], ContentTypes::getOptions(['attributes' => []]));
        self::assertTrue(ContentTypes::hasDraftAndPublish($model));
        self::assertFalse(ContentTypes::hasDraftAndPublish(['attributes' => []]));
        self::assertTrue(ContentTypes::isDraft(['publishedAt' => null], $model));
        self::assertFalse(ContentTypes::isDraft(['publishedAt' => '2020'], $model));
    }

    public function testKindsAndSchemaChecks(): void
    {
        self::assertTrue(ContentTypes::isCollectionType(['attributes' => []]));
        self::assertTrue(ContentTypes::isSingleType(['kind' => 'singleType']));
        self::assertTrue((ContentTypes::isKind('singleType'))(['kind' => 'singleType']));
        self::assertTrue(ContentTypes::isSchema(['modelType' => 'component']));
        self::assertFalse(ContentTypes::isSchema(['modelType' => 'nope']));
        self::assertTrue(ContentTypes::isComponentSchema(['modelType' => 'component']));
        self::assertTrue(ContentTypes::isContentTypeSchema(['modelType' => 'contentType']));
        self::assertSame('articles', ContentTypes::getContentTypeRoutePrefix(['kind' => 'collectionType', 'info' => ['singularName' => 'article', 'pluralName' => 'articles']]));
        self::assertSame('home-page', ContentTypes::getContentTypeRoutePrefix(['kind' => 'singleType', 'info' => ['singularName' => 'homePage', 'pluralName' => 'homePages']]));
        self::assertTrue(ContentTypes::getDoesPluginOptionHaveValue(['pluginOptions' => ['i18n' => ['localized' => true]]], 'i18n', 'localized'));
        self::assertFalse(ContentTypes::getDoesPluginOptionHaveValue(['pluginOptions' => []], 'i18n', 'localized'));
    }

    public function testReservedNames(): void
    {
        self::assertTrue(ContentTypes::isReservedAttributeName('createdAt'));
        self::assertTrue(ContentTypes::isReservedAttributeName('strapi_foo'));
        self::assertTrue(ContentTypes::isReservedAttributeName('status'));
        self::assertFalse(ContentTypes::isReservedAttributeName('status', false));
        self::assertFalse(ContentTypes::isReservedAttributeName('title'));
        self::assertTrue(ContentTypes::isReservedModelName('dateTime'));
        self::assertTrue(ContentTypes::isReservedModelName('__strapi_x'));
        self::assertFalse(ContentTypes::isReservedModelName('article'));
        self::assertSame(['status'], ContentTypes::findDraftAndPublishReservedAttributeNames(['title', 'status']));
        self::assertContains('status', ContentTypes::getReservedAttributeNames());
        self::assertNotContains('status', ContentTypes::getReservedAttributeNames(false));
    }

    public function testAcceptsSchemaObjects(): void
    {
        $schema = new Schema(
            uid: 'api::article.article',
            modelType: 'contentType',
            kind: 'collectionType',
            modelName: 'article',
            globalId: 'Article',
            collectionName: 'articles',
            plugin: null,
            apiName: 'article',
            category: null,
            info: ['singularName' => 'article', 'pluralName' => 'articles', 'displayName' => 'Article'],
            options: ['draftAndPublish' => true, 'privateAttributes' => ['secret']],
            pluginOptions: [],
            attributes: ['title' => ['type' => 'string'], 'secret' => ['type' => 'string'], 'password' => ['type' => 'password', 'private' => true], 'createdAt' => ['type' => 'datetime']],
        );

        self::assertTrue(ContentTypes::hasDraftAndPublish($schema));
        self::assertTrue(ContentTypes::isContentTypeSchema($schema));
        self::assertTrue(ContentTypes::isCollectionType($schema));
        self::assertSame(['secret', 'password'], ContentTypes::getPrivateAttributes($schema));
        self::assertTrue(ContentTypes::isPrivateAttribute($schema, 'secret'));
        self::assertSame(['id', 'documentId', 'createdAt'], ContentTypes::getNonWritableAttributes($schema));
        self::assertSame('articles', ContentTypes::getContentTypeRoutePrefix($schema));
        self::assertSame(['type' => 'string'], ContentTypes::attribute($schema, 'title'));
        self::assertSame('api::article.article', ContentTypes::toArray($schema)['uid']);
    }
}
