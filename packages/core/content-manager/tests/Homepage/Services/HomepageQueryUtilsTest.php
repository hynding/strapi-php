<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Homepage\Services;

require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Homepage\Services\HomepageQueryUtils;
use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\Rule;
use Strapi\Types\Schema\Schema;

/** Port of server/src/homepage/services/__tests__/homepage-query-utils.test.ts. */
final class HomepageQueryUtilsTest extends TestCase
{
    private static function localizedArticleContentType(): Schema
    {
        return StubStrapi::schema('api::article.article', [
            'options' => ['draftAndPublish' => true],
            'pluginOptions' => ['i18n' => ['localized' => true]],
            'attributes' => ['title' => ['type' => 'string'], 'price' => ['type' => 'integer']],
        ]);
    }

    private static function simpleContentType(): Schema
    {
        return StubStrapi::schema('api::page.page', [
            'options' => ['draftAndPublish' => false],
            'attributes' => ['slug' => ['type' => 'string']],
        ]);
    }

    private static ?Strapi $strapi = null;

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    /**
     * A permission checker whose ability reads every field of the article but `$unreadableFields`
     * (upstream: a `cannot.read(entity, field)` stub).
     *
     * @param list<string> $unreadableFields
     */
    private static function createPermissionChecker(array $unreadableFields = []): PermissionChecker
    {
        self::$strapi ??= StubStrapi::boot();
        $readable = array_values(array_diff(['title', 'price', 'documentId'], $unreadableFields));
        $ability = new Ability([new Rule(PermissionChecker::ACTIONS['read'], 'api::article.article', $readable)]);

        return (new PermissionChecker(self::$strapi))->create(['userAbility' => $ability, 'model' => 'api::article.article']);
    }

    public function testCompactSanitizedFieldsDropsNonStringEntries(): void
    {
        self::assertSame(['documentId', 'updatedAt', 'title'], HomepageQueryUtils::compactSanitizedFields(['documentId', 'updatedAt', null, 'title']));
    }

    public function testCompactSanitizedFieldsReturnsNullWhenFieldsIsNotAStringArray(): void
    {
        self::assertNull(HomepageQueryUtils::compactSanitizedFields(null));
        self::assertNull(HomepageQueryUtils::compactSanitizedFields('title'));
    }

    public function testFallsBackToDocumentIdWhenTheRoleCannotReadTheConfiguredMainField(): void
    {
        self::assertSame(HomepageQueryUtils::FALLBACK_MAIN_FIELD, HomepageQueryUtils::resolveReadableMainField(
            self::localizedArticleContentType(),
            ['settings' => ['mainField' => 'title']],
            self::createPermissionChecker(['title']),
        ));
    }

    public function testKeepsTheConfiguredMainFieldWhenTheRoleCanReadIt(): void
    {
        self::assertSame('title', HomepageQueryUtils::resolveReadableMainField(
            self::localizedArticleContentType(),
            ['settings' => ['mainField' => 'title']],
            self::createPermissionChecker(),
        ));
    }

    public function testUsesTheSchemaDefaultMainFieldWhenConfigurationIsMissing(): void
    {
        self::assertSame('title', HomepageQueryUtils::resolveReadableMainField(self::localizedArticleContentType(), null, self::createPermissionChecker()));
    }

    public function testRequestsBaseStatusAndLocaleFieldsWithoutDuplicatingDocumentIdAsMainField(): void
    {
        self::assertSame(
            ['documentId', 'updatedAt', 'publishedAt', 'locale'],
            HomepageQueryUtils::buildHomepageQueryFields(self::localizedArticleContentType(), HomepageQueryUtils::FALLBACK_MAIN_FIELD),
        );
    }

    public function testIncludesTheReadableMainFieldForDraftAndPublishLocalizedContentTypes(): void
    {
        self::assertSame(
            ['documentId', 'updatedAt', 'publishedAt', 'title', 'locale'],
            HomepageQueryUtils::buildHomepageQueryFields(self::localizedArticleContentType(), 'title'),
        );
    }

    public function testRequestsOnlyDocumentIdUpdatedAtAndMainFieldForSimpleContentTypes(): void
    {
        self::assertSame(['documentId', 'updatedAt', 'slug'], HomepageQueryUtils::buildHomepageQueryFields(self::simpleContentType(), 'slug'));
    }

    public function testResolveTitleField(): void
    {
        self::assertSame(HomepageQueryUtils::FALLBACK_MAIN_FIELD, HomepageQueryUtils::resolveTitleField('title', ['documentId', 'updatedAt']));
        self::assertSame('title', HomepageQueryUtils::resolveTitleField('title', ['documentId', 'title']));
    }
}
