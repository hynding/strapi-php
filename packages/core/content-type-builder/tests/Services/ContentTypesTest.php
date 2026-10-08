<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Services\ContentTypes;
use Strapi\ContentTypeBuilder\Tests\StubStrapi;

require_once __DIR__ . '/../StubStrapi.php';

/** Port of server/src/services/__tests__/content-types.test.ts (and its snapshot). */
final class ContentTypesTest extends TestCase
{
    public function testFormatContentTypeReturnsConsistentSchemas(): void
    {
        $contentType = StubStrapi::schema('test-uid', [
            'kind' => 'singleType',
            'plugin' => 'some-plugin',
            'modelName' => 'my-name',
            'collectionName' => 'tests',
            'info' => [
                'displayName' => 'My name',
                'singularName' => 'my-name',
                'pluralName' => 'my-names',
                'description' => 'My description',
            ],
            'options' => [],
            'pluginOptions' => ['content-manager' => ['visible' => true]],
            'attributes' => ['title' => ['type' => 'string']],
        ]);

        $formatted = ContentTypes::formatContentType($contentType);
        ksort($formatted);
        ksort($formatted['schema']);

        self::assertSame([
            'apiID' => 'my-name',
            'plugin' => 'some-plugin',
            'schema' => [
                'attributes' => ['title' => ['type' => 'string']],
                'collectionName' => 'tests',
                'description' => 'My description',
                'displayName' => 'My name',
                'draftAndPublish' => false,
                'kind' => 'singleType',
                'pluginOptions' => ['content-manager' => ['visible' => true]],
                'pluralName' => 'my-names',
                'restrictRelationsTo' => null,
                'singularName' => 'my-name',
                'visible' => true,
            ],
            'uid' => 'test-uid',
        ], $formatted);
    }
}
