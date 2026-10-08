<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Controllers\Validation;

require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Controllers\Validation\Dimensions;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/controllers/validation/__tests__/dimensions.test.ts. */
final class DimensionsTest extends TestCase
{
    private const string WITH_DP = 'application::model.model';
    private const string WITHOUT_DP = 'application::nodraft.nodraft';

    private Strapi $strapi;

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
        StubStrapi::addContentTypes($this->strapi, [
            self::WITH_DP => ['options' => ['draftAndPublish' => true]],
            self::WITHOUT_DP => [],
        ]);
    }

    /**
     * Upstream's result with `undefined` locale / status left out (toEqual ignores them).
     *
     * @param array<string, mixed> $request
     * @param array{allowMultipleLocales?: bool} $opts
     * @return array<string, mixed>
     */
    private function dimensions(array $request, string $model, array $opts = ['allowMultipleLocales' => false]): array
    {
        return array_filter(Dimensions::getDocumentLocaleAndStatus($this->strapi, $request, $model, $opts), static fn (mixed $v): bool => $v !== null);
    }

    public function testInvalidStatus(): void
    {
        $this->expectException(ValidationError::class);
        $this->dimensions(['locale' => 'en', 'status' => 'notAStatus'], self::WITH_DP);
    }

    public function testInvalidLocaleStringArrayWhenNotSupported(): void
    {
        $this->expectException(ValidationError::class);
        // Multiple locales are not supported here
        $this->dimensions(['locale' => ['en', 'fr'], 'status' => 'draft'], self::WITH_DP);
    }

    public function testInvalidLocaleMixedArray(): void
    {
        $this->expectException(ValidationError::class);
        // Numbers are not allowed as locales
        $this->dimensions(['locale' => ['en', 'fr', 123], 'status' => 'published'], self::WITH_DP, ['allowMultipleLocales' => true]);
    }

    public function testNeitherStatusOrLocaleAreRequired(): void
    {
        self::assertSame([], $this->dimensions([], self::WITH_DP));
    }

    public function testStatusModifiedIsInvalid(): void
    {
        $this->expectException(ValidationError::class);
        $this->dimensions(['status' => 'modified'], self::WITH_DP);
    }

    public function testValidStatusOnly(): void
    {
        self::assertSame(['status' => 'draft'], $this->dimensions(['status' => 'draft'], self::WITH_DP));
    }

    public function testValidLocaleOnly(): void
    {
        self::assertSame(['locale' => 'en'], $this->dimensions(['locale' => 'en'], self::WITH_DP));
    }

    public function testValidStatusAndLocale(): void
    {
        self::assertSame(['locale' => 'en', 'status' => 'published'], $this->dimensions(['locale' => 'en', 'status' => 'published'], self::WITH_DP));
    }

    public function testDefaultStatusToPublishedIfTheModelDoesNotHaveDraftAndPublishEnabled(): void
    {
        self::assertSame(['locale' => 'en', 'status' => 'published'], $this->dimensions(['locale' => 'en'], self::WITHOUT_DP));
    }

    public function testMultipleLocalesAreAllowedWhenEnabled(): void
    {
        self::assertSame(
            ['locale' => ['en', 'fr'], 'status' => 'published'],
            $this->dimensions(['locale' => ['en', 'fr'], 'status' => 'published'], self::WITH_DP, ['allowMultipleLocales' => true]),
        );
    }
}
