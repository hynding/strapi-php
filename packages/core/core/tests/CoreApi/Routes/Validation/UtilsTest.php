<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\CoreApi\Routes\Validation;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Strapi\Core\CoreApi\Routes\Validation\SchemaRegistry;
use Strapi\Core\CoreApi\Routes\Validation\Utils;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodType;

/** Port of packages/core/core/src/core-api/routes/validation/__tests__/utils.test.ts. */
final class UtilsTest extends TestCase
{
    private object $strapi;

    protected function setUp(): void
    {
        $this->strapi = new class () {
            public readonly SchemaRegistry $registry;

            public function __construct()
            {
                $this->registry = SchemaRegistry::createContentAPISchemaRegistry();
            }

            public function log(): NullLogger
            {
                return new NullLogger();
            }

            public function contentAPISchemaRegistry(): SchemaRegistry
            {
                return $this->registry;
            }
        };
    }

    public function testReturnsTheRegisteredSchemaWithoutRebuildingIt(): void
    {
        $schema = Utils::safeSchemaCreation($this->strapi, 'api::article.article', static fn (): ZodType => z::object(['title' => z::string()]));
        $called = false;
        $callback = static function () use (&$called): ZodType {
            $called = true;

            return z::never();
        };

        $existingSchema = Utils::safeSchemaCreation($this->strapi, 'api::article.article', $callback);

        self::assertSame($schema, $existingSchema);
        self::assertFalse($called);
    }

    public function testDefersCyclicalLookupsUntilTheRealSchemaReplacesThePlaceholder(): void
    {
        $cyclicalSchema = null;
        $strapi = $this->strapi;

        $schema = Utils::safeSchemaCreation($strapi, 'api::article.article', static function () use ($strapi, &$cyclicalSchema): ZodType {
            $cyclicalSchema = Utils::safeSchemaCreation($strapi, 'api::article.article', static fn (): ZodType => z::never());

            return z::object(['title' => z::string()]);
        });

        self::assertInstanceOf(ZodType::class, $cyclicalSchema);
        self::assertTrue($cyclicalSchema->safeParse(['title' => 'Article'])['success']);
        self::assertSame($schema, $strapi->contentAPISchemaRegistry()->get('ApiArticleArticleDocument'));
        self::assertTrue($schema->safeParse(['title' => 'Article'])['success']);
    }
}
