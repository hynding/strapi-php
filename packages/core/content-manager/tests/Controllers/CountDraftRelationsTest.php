<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Controllers;

require_once __DIR__ . '/../StubStrapi.php';
require_once __DIR__ . '/../Mock.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Controllers\CollectionTypes;
use Strapi\ContentManager\Tests\Mock;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Services\Server\Context;

/** Port of server/src/controllers/__tests__/countDraftRelations.test.ts. */
final class CountDraftRelationsTest extends TestCase
{
    private Mock $permissionChecker;
    private Mock $builder;
    private Mock $documentManager;

    /** @param array<string, mixed> $overrides */
    private function controller(array $overrides = []): CollectionTypes
    {
        $strapi = StubStrapi::create();
        StubStrapi::addContentTypes($strapi, ['test-model' => []]);

        $this->builder = new Mock(['build' => ['createdBy' => true]]);
        $this->builder->set('populateFromQuery', fn (): Mock => $this->builder);
        $this->documentManager = new Mock([
            'findOne' => array_key_exists('findOne', $overrides) ? $overrides['findOne'] : ['id' => 1, 'createdBy' => ['id' => 1]],
            'findLocales' => $overrides['findLocales'] ?? [],
            'countDraftRelations' => ['unpublishedRelations' => 3, 'draftM2mLinks' => 0],
        ]);
        $this->permissionChecker = new Mock([
            'cannot' => $overrides['cannot'] ?? false,
            'requiresEntity' => $overrides['requiresEntity'] ?? true,
            'sanitizedQuery' => [],
        ]);

        StubStrapi::setService($strapi, 'permission-checker', new Mock(['create' => $this->permissionChecker]));
        StubStrapi::setService($strapi, 'populate-builder', new Mock(['invoke' => $this->builder]));
        StubStrapi::setService($strapi, 'document-manager', $this->documentManager);

        return new CollectionTypes($strapi);
    }

    private static function ctx(): Context
    {
        return StubStrapi::ctx(params: ['model' => 'test-model', 'id' => 'doc-1'], state: ['userAbility' => null]);
    }

    public function testBuildsPopulateFromThePermissionQueryAndPassesItToFindOne(): void
    {
        $this->controller()->countDraftRelations(self::ctx());

        self::assertSame([[], 'read'], $this->permissionChecker->calls['sanitizedQuery'][0]);
        self::assertSame([[]], $this->builder->calls['populateFromQuery'][0]);
        [$id, $model, $opts] = $this->documentManager->calls['findOne'][0];
        self::assertSame(['doc-1', 'test-model'], [$id, $model]);
        self::assertSame(['createdBy' => true], $opts['populate']);
    }

    public function testReturnsTheDraftRelationCountOnSuccess(): void
    {
        $res = $this->controller()->countDraftRelations(self::ctx());

        self::assertSame(['data' => ['unpublishedRelations' => 3, 'draftM2mLinks' => 0]], $res);
    }

    public function testReturns403WhenTheUserLacksReadPermissionEntirely(): void
    {
        $ctx = self::ctx();
        $this->controller(['cannot' => true])->countDraftRelations($ctx);

        self::assertSame(403, $ctx->status());
        self::assertSame(0, $this->documentManager->called('findOne'));
    }

    public function testReturns404WhenTheDocumentDoesNotExistAtAll(): void
    {
        $ctx = self::ctx();
        $this->controller(['findOne' => null, 'findLocales' => []])->countDraftRelations($ctx);

        self::assertSame(404, $ctx->status());
        self::assertSame(0, $this->documentManager->called('countDraftRelations'));
    }

    public function testReturnsZeroCountsWhenTheDocumentExistsButNotInTheRequestedLocale(): void
    {
        $ctx = self::ctx();
        $res = $this->controller(['findOne' => null, 'findLocales' => [['id' => 1, 'createdBy' => ['id' => 1]]]])->countDraftRelations($ctx);

        // ctx.notFound() was not called: nothing set the error body
        self::assertNull($ctx->body());
        self::assertSame(['data' => ['unpublishedRelations' => 0, 'draftM2mLinks' => 0]], $res);
        self::assertSame(0, $this->documentManager->called('countDraftRelations'));
    }

    public function testReturns403WhenTheRequestedLocaleIsMissingAndEntityLevelRbacDeniesTheDocument(): void
    {
        $ctx = self::ctx();
        $this->controller([
            'findOne' => null,
            'findLocales' => [['id' => 1, 'createdBy' => ['id' => 2]]],
            'cannot' => static fn (string $action, mixed $entity = null): bool => $entity !== null,
        ])->countDraftRelations($ctx);

        self::assertSame(403, $ctx->status());
        self::assertSame(0, $this->documentManager->called('countDraftRelations'));
    }

    public function testReturns403WhenEntityLevelRbacConditionFails(): void
    {
        $ctx = self::ctx();
        $this->controller(['cannot' => static fn (string $action, mixed $entity = null): bool => $entity !== null])->countDraftRelations($ctx);

        self::assertSame(403, $ctx->status());
        self::assertSame(['createdBy' => true], $this->documentManager->calls['findOne'][0][2]['populate']);
    }

    public function testSkipsEntityLoadWhenRbacDoesNotRequireIt(): void
    {
        $res = $this->controller(['requiresEntity' => false])->countDraftRelations(self::ctx());

        self::assertSame(['data' => ['unpublishedRelations' => 3, 'draftM2mLinks' => 0]], $res);
        self::assertSame(0, $this->documentManager->called('findOne'));
        self::assertSame(0, $this->builder->called('populateFromQuery'));
    }
}
