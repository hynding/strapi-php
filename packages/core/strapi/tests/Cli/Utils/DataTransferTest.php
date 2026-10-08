<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Cli\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Cli\Cli\Utils\DataTransfer;
use Strapi\Cli\Cli\Utils\ExitError;

/**
 * Ports of packages/core/strapi/src/cli/utils/__tests__: normalize-transfer-filter-options,
 * parse-restore-from-options, validate-content-type-transfer-options,
 * content-type-transfer-filters and log-transfer-filter-summary. `process.exit(1)` is an
 * {@see ExitError} with code 1 here.
 */
final class DataTransferTest extends TestCase
{
    private const array UPLOAD = DataTransfer::UPLOAD_CONTENT_TYPE_UIDS;

    private const array CONTENT_TYPES = [
        'api::article.article' => [],
        'api::category.category' => [],
        'admin::user' => [],
        'plugin::content-releases.release' => [],
        'plugin::upload.file' => [],
        'plugin::upload.folder' => [],
    ];

    /**
     * @param array<string, mixed> $opts
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $opts): array
    {
        DataTransfer::normalizeTransferFilterOptions($opts);

        return $opts;
    }

    // normalize-transfer-filter-options.test.ts

    public function testExpandsExcludeMediaLibraryIntoFilesAndUploadContentTypes(): void
    {
        $opts = self::normalize(['exclude' => ['media-library']]);

        self::assertSame(['files'], $opts['exclude']);
        self::assertSame(self::UPLOAD, $opts['excludeContentTypes']);
    }

    public function testMergesMediaLibraryWithExistingExcludeContentTypes(): void
    {
        $opts = self::normalize(['exclude' => ['media-library'], 'excludeContentTypes' => ['api::article.article']]);

        self::assertSame(['files'], $opts['exclude']);
        foreach (['api::article.article', ...self::UPLOAD] as $uid) {
            self::assertContains($uid, $opts['excludeContentTypes']);
        }
    }

    public function testAutoExcludesFilesWhenUploadTypesAreOutOfScopeViaOnlyContentTypes(): void
    {
        $opts = self::normalize(['onlyContentTypes' => ['api::article.article']]);

        self::assertSame(['files'], $opts['exclude']);
        self::assertTrue($opts['filesAutoExcluded']);
        self::assertFalse(DataTransfer::areUploadContentTypesInTransferScope($opts));
    }

    public function testAutoExcludesFilesWhenBothUploadTypesAreExcluded(): void
    {
        $opts = self::normalize(['excludeContentTypes' => self::UPLOAD]);

        self::assertSame(['files'], $opts['exclude']);
        self::assertTrue($opts['filesAutoExcluded']);
    }

    public function testDoesNotAutoExcludeFilesWhenOnlyOneUploadTypeIsExcluded(): void
    {
        $opts = self::normalize(['excludeContentTypes' => ['plugin::upload.file']]);

        self::assertArrayNotHasKey('exclude', $opts);
        self::assertArrayNotHasKey('filesAutoExcluded', $opts);
    }

    public function testDoesNotAutoExcludeFilesWhenOnlyOneUploadTypeIsInScope(): void
    {
        $opts = self::normalize(['onlyContentTypes' => ['plugin::upload.file']]);

        self::assertArrayNotHasKey('exclude', $opts);
        self::assertArrayNotHasKey('filesAutoExcluded', $opts);
        self::assertFalse(DataTransfer::areUploadContentTypesInTransferScope($opts));
        self::assertFalse(DataTransfer::areAllUploadContentTypesOutOfTransferScope($opts));
    }

    public function testDoesNotAutoExcludeFilesWhenContentStageIsInactive(): void
    {
        $opts = self::normalize(['only' => ['config'], 'onlyContentTypes' => ['api::article.article']]);

        self::assertArrayNotHasKey('exclude', $opts);
        self::assertArrayNotHasKey('filesAutoExcluded', $opts);
    }

    public function testDoesNotAutoExcludeFilesWhenUserExplicitlyRequestsFilesStage(): void
    {
        $opts = self::normalize(['only' => ['files'], 'onlyContentTypes' => ['api::article.article']]);

        self::assertArrayNotHasKey('exclude', $opts);
        self::assertArrayNotHasKey('filesAutoExcluded', $opts);
    }

    public function testDoesNotAutoExcludeFilesWhenUploadTypesRemainInScope(): void
    {
        $opts = self::normalize(['onlyContentTypes' => ['api::article.article', ...self::UPLOAD]]);

        self::assertArrayNotHasKey('exclude', $opts);
        self::assertArrayNotHasKey('filesAutoExcluded', $opts);
        self::assertTrue(DataTransfer::areUploadContentTypesInTransferScope($opts));
    }

    public function testIsIdempotentWhenCalledTwice(): void
    {
        $opts = self::normalize(self::normalize(['onlyContentTypes' => ['api::article.article']]));

        self::assertSame(['files'], $opts['exclude']);
        self::assertTrue($opts['filesAutoExcluded']);
    }

    // parse-restore-from-options.test.ts

    public function testFullTransferDeletesAllEntitiesBeforeRestore(): void
    {
        $restore = DataTransfer::parseRestoreFromOptions([], self::CONTENT_TYPES);

        self::assertArrayNotHasKey('include', $restore['entities'] ?? []);
        self::assertTrue($restore['configuration']['coreStore']);
        self::assertTrue($restore['configuration']['webhook']);
        self::assertTrue($restore['assets']);
    }

    public function testOnlyContentPreservesConfigAndScopesEntityDeletionToContentTypes(): void
    {
        $restore = DataTransfer::parseRestoreFromOptions(['only' => ['content']], self::CONTENT_TYPES);

        self::assertSame(['api::article.article', 'api::category.category', 'plugin::upload.file', 'plugin::upload.folder'], $restore['entities']['include']);
        self::assertFalse($restore['configuration']['coreStore']);
        self::assertFalse($restore['configuration']['webhook']);
        self::assertFalse($restore['assets']);
    }

    public function testExcludeConfigPreservesConfigAndScopesEntityDeletionToContentTypes(): void
    {
        $restore = DataTransfer::parseRestoreFromOptions(['exclude' => ['config']], self::CONTENT_TYPES);

        self::assertSame(['api::article.article', 'api::category.category', 'plugin::upload.file', 'plugin::upload.folder'], $restore['entities']['include']);
        self::assertFalse($restore['configuration']['coreStore']);
        self::assertFalse($restore['configuration']['webhook']);
        self::assertTrue($restore['assets']);
    }

    public function testOnlyContentAndConfigStillWipesAllEntitiesBeforeRestore(): void
    {
        $restore = DataTransfer::parseRestoreFromOptions(['only' => ['content', 'config']], self::CONTENT_TYPES);

        self::assertArrayNotHasKey('include', $restore['entities'] ?? []);
        self::assertTrue($restore['configuration']['coreStore']);
        self::assertTrue($restore['configuration']['webhook']);
    }

    public function testOnlyConfigDoesNotDeleteEntitiesBeforeRestore(): void
    {
        $restore = DataTransfer::parseRestoreFromOptions(['only' => ['config']], self::CONTENT_TYPES);

        self::assertSame([], $restore['entities']['include']);
        self::assertTrue($restore['configuration']['coreStore']);
        self::assertTrue($restore['configuration']['webhook']);
        self::assertFalse($restore['assets']);
    }

    public function testExcludeContentTypesPreservesExcludedTypesDuringRestore(): void
    {
        $restore = DataTransfer::parseRestoreFromOptions(['excludeContentTypes' => self::UPLOAD], self::CONTENT_TYPES);

        foreach (self::UPLOAD as $uid) {
            self::assertContains($uid, $restore['entities']['exclude']);
        }
    }

    public function testOnlyContentTypesScopesRestoreDeletionToListedTypes(): void
    {
        $restore = DataTransfer::parseRestoreFromOptions(['onlyContentTypes' => ['api::article.article'], 'exclude' => ['files'], 'filesAutoExcluded' => true], self::CONTENT_TYPES);

        self::assertSame(['api::article.article'], $restore['entities']['include']);
        self::assertFalse($restore['assets']);
    }

    public function testOnlyContentTypesIncludingUploadTypesStillRestoresAssetsWhenFilesStageIsActive(): void
    {
        self::assertTrue(DataTransfer::parseRestoreFromOptions(['onlyContentTypes' => self::UPLOAD], self::CONTENT_TYPES)['assets']);
    }

    public function testOnlyContentTypesWithUploadTypesDoesNotRestoreAssetsWhenFilesStageIsSkipped(): void
    {
        self::assertFalse(DataTransfer::parseRestoreFromOptions(['onlyContentTypes' => self::UPLOAD, 'exclude' => ['files']], self::CONTENT_TYPES)['assets']);
    }

    // validate-content-type-transfer-options.test.ts

    public function testAllowsExcludeOrOnlyContentTypesAloneAndNonOverlappingCombinations(): void
    {
        DataTransfer::validateContentTypeTransferOptions(['excludeContentTypes' => ['plugin::upload.file']]);
        DataTransfer::validateContentTypeTransferOptions(['onlyContentTypes' => ['api::article.article']]);
        DataTransfer::validateContentTypeTransferOptions(['excludeContentTypes' => ['plugin::upload.file'], 'onlyContentTypes' => ['api::article.article']]);

        $this->addToAssertionCount(1);
    }

    public function testRejectsOverlappingExcludeAndOnlyContentTypes(): void
    {
        try {
            DataTransfer::validateContentTypeTransferOptions([
                'excludeContentTypes' => ['api::article.article', 'plugin::upload.file'],
                'onlyContentTypes' => ['api::article.article', 'api::category.category'],
            ]);
            self::fail('Expected an exit');
        } catch (ExitError $exit) {
            self::assertSame(1, $exit->exitCode);
            self::assertStringContainsString('api::article.article', $exit->getMessage());
            self::assertStringContainsString('"--exclude-content-types" and "--only-content-types"', $exit->getMessage());
        }
    }

    public function testValidateForStrapiAcceptsKnownContentTypes(): void
    {
        DataTransfer::validateContentTypeTransferOptionsForStrapi(['excludeContentTypes' => ['plugin::upload.file'], 'onlyContentTypes' => ['api::article.article']], self::CONTENT_TYPES);

        $this->addToAssertionCount(1);
    }

    public function testValidateForStrapiRejectsUnknownContentTypes(): void
    {
        foreach (['excludeContentTypes' => '--exclude-content-types', 'onlyContentTypes' => '--only-content-types'] as $option => $flag) {
            try {
                DataTransfer::validateContentTypeTransferOptionsForStrapi([$option => ['plugin::does-not-exist']], self::CONTENT_TYPES);
                self::fail('Expected an exit');
            } catch (ExitError $exit) {
                self::assertSame(1, $exit->exitCode);
                self::assertStringContainsString("Unknown content type(s) for {$flag}", $exit->getMessage());
                self::assertStringContainsString('plugin::does-not-exist', $exit->getMessage());
            }
        }
    }

    // content-type-transfer-filters.test.ts

    public function testEntityAndLinkFilters(): void
    {
        $entityFilter = DataTransfer::createEntityFilter(['excludeContentTypes' => self::UPLOAD]);
        $linkFilter = DataTransfer::createLinkFilter(['excludeContentTypes' => self::UPLOAD]);

        self::assertFalse($entityFilter(['type' => 'plugin::upload.file']));
        self::assertFalse($entityFilter(['type' => 'plugin::upload.folder']));
        self::assertTrue($entityFilter(['type' => 'api::article.article']));
        // admin types and the content-release exclusions are never transferred
        self::assertFalse($entityFilter(['type' => 'admin::user']));
        self::assertFalse($entityFilter(['type' => 'plugin::content-releases.release']));

        self::assertFalse($linkFilter(['left' => ['type' => 'plugin::upload.file'], 'right' => ['type' => 'api::article.article']]));
        self::assertTrue($linkFilter(['left' => ['type' => 'api::article.article'], 'right' => ['type' => 'api::category.category']]));

        $onlyEntityFilter = DataTransfer::createEntityFilter(['onlyContentTypes' => ['api::article.article']]);
        $onlyLinkFilter = DataTransfer::createLinkFilter(['onlyContentTypes' => ['api::article.article']]);
        self::assertTrue($onlyEntityFilter(['type' => 'api::article.article']));
        self::assertFalse($onlyEntityFilter(['type' => 'api::category.category']));
        self::assertFalse($onlyLinkFilter(['left' => ['type' => 'api::article.article'], 'right' => ['type' => 'api::category.category']]));
    }

    // log-transfer-filter-summary.test.ts

    /** @param array<string, mixed> $opts */
    private static function summary(array $opts): string
    {
        return implode("\n", DataTransfer::logTransferFilterSummary($opts));
    }

    public function testSummaryDoesNothingWithoutFilters(): void
    {
        self::assertSame([], DataTransfer::logTransferFilterSummary([]));
    }

    public function testSummaryStageFilters(): void
    {
        $s = self::summary(['exclude' => ['config']]);
        self::assertStringContainsString('excluding config', $s);
        self::assertStringNotContainsString('plugin::upload.file', $s);
        self::assertStringNotContainsString('Stages not transferred', $s);

        $s = self::summary(['exclude' => ['files'], 'onlyContentTypes' => ['api::article.article'], 'filesAutoExcluded' => true]);
        self::assertStringContainsString('Skipping files stage: upload content types are not in transfer scope', $s);
        self::assertStringNotContainsString('rsync', $s);

        $s = self::summary(['exclude' => ['files']]);
        self::assertStringContainsString('excluding files', $s);
        self::assertStringContainsString('plugin::upload.file', $s);
        self::assertStringContainsString('rsync', $s);

        $s = self::summary(['only' => ['content']]);
        self::assertStringContainsString('only content', $s);
        self::assertStringContainsString('Stages not transferred (destination data preserved): files, config', $s);
        self::assertStringContainsString('plugin::upload.file', $s);

        $s = self::summary(['only' => ['content', 'files']]);
        self::assertStringContainsString('only content, files', $s);
        self::assertStringContainsString('Stages not transferred (destination data preserved): config', $s);

        $s = self::summary(['only' => ['content', 'files', 'config']]);
        self::assertStringContainsString('only content, files, config', $s);
        self::assertStringNotContainsString('Stages not transferred', $s);

        $s = self::summary(['only' => ['config']]);
        self::assertStringContainsString('Stages not transferred (destination data preserved): content, files', $s);
        self::assertStringNotContainsString('plugin::upload.file', $s);

        $s = self::summary(['exclude' => ['files', 'content']]);
        self::assertStringContainsString('excluding files, content', $s);
        self::assertStringNotContainsString('plugin::upload.file', $s);
    }

    public function testSummaryContentTypeFilters(): void
    {
        self::assertStringContainsString('Content type filters: excluding plugin::upload.file, plugin::upload.folder', self::summary(['excludeContentTypes' => self::UPLOAD]));
        self::assertStringContainsString('Content type filters: only api::article.article, api::category.category', self::summary(['onlyContentTypes' => ['api::article.article', 'api::category.category']]));

        $lines = DataTransfer::logTransferFilterSummary(['exclude' => ['files'], 'onlyContentTypes' => ['api::article.article']]);
        self::assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_contains($l, 'Transfer filters: excluding files')));
        self::assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_contains($l, 'Content type filters: only api::article.article')));
    }
}
