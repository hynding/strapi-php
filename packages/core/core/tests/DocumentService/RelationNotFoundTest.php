<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\DocumentService;

use Strapi\Core\Tests\BootedAppTestCase;
use Strapi\Utils\Errors\ValidationError;

/**
 * transform/relations/transform/data-ids.ts: connecting a document that does not exist. Upstream
 * builds the message with a template literal, so a type without i18n reads `locale "null"`.
 */
final class RelationNotFoundTest extends BootedAppTestCase
{
    public function testAMissingTargetOfAnUnlocalizedTypeNamesLocaleNull(): void
    {
        try {
            self::strapi()->documents('api::like.like')->create(['data' => ['review' => ['connect' => ['no-such-review']]]]);
            self::fail('expected a ValidationError');
        } catch (ValidationError $e) {
            self::assertSame('Document with id "no-such-review", locale "null" not found', $e->getMessage());
        }
    }
}
