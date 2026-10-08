<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Controllers\Validation;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Controllers\Validation\DataTransform;

/**
 * Port of server/src/controllers/validation/__tests__/data-transform.test.ts. Upstream sets the
 * properties to `undefined`; here they are removed (the JSON forms are equal).
 */
final class DataTransformTest extends TestCase
{
    public function testRemoveEmptyDefaultsClearsDefaults(): void
    {
        $data = ['attributes' => ['test' => ['default' => '']]];

        self::assertSame(['attributes' => ['test' => []]], DataTransform::removeEmptyDefaults($data));
    }

    public function testRemoveDeletedUIDTargetFieldsUnsetsAMissingTargetField(): void
    {
        $data = ['attributes' => ['slug' => ['type' => 'uid', 'targetField' => 'random']]];

        self::assertSame(['attributes' => ['slug' => ['type' => 'uid']]], DataTransform::removeDeletedUIDTargetFields($data));
    }
}
