<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Controllers;

require_once __DIR__ . '/../StubStrapi.php';
require_once __DIR__ . '/../Mock.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Controllers\CollectionTypes;
use Strapi\ContentManager\Tests\Mock;
use Strapi\ContentManager\Tests\StubStrapi;

/** Port of server/src/controllers/__tests__/bulkDelete.test.ts. */
final class BulkDeleteTest extends TestCase
{
    public function testDeletesEachDocumentOnlyOnceWhenDraftAndPublishReturnsBothDraftAndPublishedRows(): void
    {
        $strapi = StubStrapi::create();
        StubStrapi::addContentTypes($strapi, ['test-model' => []]);

        $builder = new Mock(['build' => []]);
        $builder->set('populateFromQuery', static fn (): Mock => $builder);
        // With draft & publish enabled, findLocales returns one row per (locale, publication state),
        // so a single document yields two rows that share the same documentId.
        $documentManager = new Mock([
            'findLocales' => [
                ['documentId' => 'doc-1', 'locale' => 'en', 'publishedAt' => null],
                ['documentId' => 'doc-1', 'locale' => 'en', 'publishedAt' => '2026-01-01T00:00:00.000Z'],
            ],
            'deleteMany' => ['count' => 1],
        ]);
        StubStrapi::setService($strapi, 'permission-checker', new Mock(['create' => new Mock(['cannot' => false, 'sanitizedQuery' => []])]));
        StubStrapi::setService($strapi, 'populate-builder', new Mock(['invoke' => $builder]));
        StubStrapi::setService($strapi, 'document-manager', $documentManager);

        $ctx = StubStrapi::ctx('POST', ['documentIds' => ['doc-1'], 'locale' => 'en'], params: ['model' => 'test-model'], state: ['userAbility' => null]);
        (new CollectionTypes($strapi))->bulkDelete($ctx);

        self::assertSame(1, $documentManager->called('deleteMany'));
        self::assertSame([['doc-1'], 'test-model', ['locale' => 'en']], $documentManager->calls['deleteMany'][0]);
        self::assertSame(['count' => 1], $ctx->body());
    }
}
