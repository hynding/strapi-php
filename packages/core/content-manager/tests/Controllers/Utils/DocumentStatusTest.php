<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Controllers\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Controllers\Utils\DocumentStatus;

/**
 * Port of server/src/controllers/__tests__/status-lookup.test.ts: the status map it replicates
 * is `indexByDocumentId` (controllers/utils/document-status), tested directly here.
 */
final class DocumentStatusTest extends TestCase
{
    public function testShouldGroupStatusesByDocumentId(): void
    {
        $result = DocumentStatus::indexByDocumentId([
            ['documentId' => 'doc1', 'status' => 'draft'],
            ['documentId' => 'doc2', 'status' => 'published'],
            ['documentId' => 'doc1', 'status' => 'published'],
        ]);

        self::assertCount(2, $result['doc1']);
        self::assertCount(1, $result['doc2']);
        self::assertSame('draft', $result['doc1'][0]['status']);
        self::assertSame('published', $result['doc1'][1]['status']);
    }

    public function testShouldSkipEntriesWithoutAValidDocumentId(): void
    {
        $result = DocumentStatus::indexByDocumentId([
            ['documentId' => 'doc1', 'status' => 'draft'],
            ['documentId' => null, 'status' => 'orphan'],
            ['status' => 'undefined'],
            ['documentId' => '', 'status' => 'empty'],
            ['documentId' => 'doc2', 'status' => 'published'],
        ]);

        self::assertSame(['doc1', 'doc2'], array_keys($result));
    }

    public function testShouldReturnAnEmptyMapForEmptyInput(): void
    {
        self::assertSame([], DocumentStatus::indexByDocumentId([]));
        self::assertSame([], DocumentStatus::indexByDocumentId([['documentId' => null], ['documentId' => null]]));
    }

    public function testShouldFilterByLocaleWhenSpecified(): void
    {
        $map = DocumentStatus::indexByDocumentId([
            ['documentId' => 'doc1', 'locale' => 'en'],
            ['documentId' => 'doc1', 'locale' => 'fr'],
        ]);
        $candidates = $map['doc1'];
        $fr = array_values(array_filter($candidates, static fn (array $c): bool => $c['locale'] === 'fr'));

        self::assertCount(2, $candidates);
        self::assertCount(1, $fr);
        self::assertSame([], $map['missing'] ?? []);
    }
}
