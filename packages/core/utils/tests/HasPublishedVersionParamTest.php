<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\HasPublishedVersionParam;

/** Port of packages/core/utils/src/__tests__/has-published-version-param.test.ts. */
final class HasPublishedVersionParamTest extends TestCase
{
    public function testReturnsUndefinedForUndefinedAndNull(): void
    {
        self::assertNull(HasPublishedVersionParam::parseHasPublishedVersionQueryParam(null));
    }

    public function testParsesTrue(): void
    {
        self::assertTrue(HasPublishedVersionParam::parseHasPublishedVersionQueryParam(true));
        self::assertTrue(HasPublishedVersionParam::parseHasPublishedVersionQueryParam('true'));
    }

    public function testParsesFalse(): void
    {
        self::assertFalse(HasPublishedVersionParam::parseHasPublishedVersionQueryParam(false));
        self::assertFalse(HasPublishedVersionParam::parseHasPublishedVersionQueryParam('false'));
    }

    public function testThrowsOnOtherValues(): void
    {
        foreach (['yes', 1] as $value) {
            try {
                HasPublishedVersionParam::parseHasPublishedVersionQueryParam($value);
                self::fail('expected a ValidationError');
            } catch (ValidationError $e) {
                self::assertSame("Invalid value for 'hasPublishedVersion'. Expected boolean or 'true'/'false' string.", $e->getMessage());
            }
        }
    }

    public function testMapsToDocumentScopedPublicationFilterModes(): void
    {
        self::assertSame('has-published-version-document', HasPublishedVersionParam::hasPublishedVersionBooleanToPublicationFilterMode(true));
        self::assertSame('never-published-document', HasPublishedVersionParam::hasPublishedVersionBooleanToPublicationFilterMode(false));
    }
}
