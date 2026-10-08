<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\CoreApi\Routes\Validation;

use PHPUnit\Framework\TestCase;
use Strapi\Core\CoreApi\Routes\Validation\CoreContentTypeRouteValidator;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Zod as z;

/** Port of packages/core/core/src/core-api/routes/validation/__tests__/content-type.test.ts. */
final class ContentTypeTest extends TestCase
{
    private static function validator(): CoreContentTypeRouteValidator
    {
        $strapi = new class () {
            public function getModel(string $uid): Schema
            {
                return new Schema(
                    uid: $uid,
                    modelType: 'contentType',
                    kind: 'collectionType',
                    modelName: 'article',
                    globalId: 'Article',
                    collectionName: 'articles',
                    plugin: null,
                    apiName: 'article',
                    category: null,
                    info: [],
                    options: [],
                    pluginOptions: [],
                    attributes: [
                        'title' => ['type' => 'string'],
                        'rating' => ['type' => 'integer'],
                    ],
                );
            }
        };

        return new CoreContentTypeRouteValidator($strapi, 'api::article.article');
    }

    public function testZodEnumKeyedRecordsAreExhaustiveByDefault(): void
    {
        $exhaustiveRecord = z::record(z::enum(['title', 'rating']), z::string());

        self::assertFalse($exhaustiveRecord->safeParse(['title' => 'asc'])['success']);
    }

    public function testSortAcceptsSparseEnumKeyedObjects(): void
    {
        $sort = self::validator()->queryParams(['sort'])['sort'];

        self::assertSame(['title' => 'asc'], $sort->parse(['title' => 'asc']));
        self::assertFalse($sort->safeParse(['unknown' => 'asc'])['success']);
    }

    public function testFiltersAcceptSparseEnumKeyedObjects(): void
    {
        $filters = self::validator()->queryParams(['filters'])['filters'];

        self::assertSame(['title' => ['$eq' => 'hello']], $filters->parse(['title' => ['$eq' => 'hello']]));
        self::assertFalse($filters->safeParse(['unknown' => ['$eq' => 'hello']])['success']);
    }
}
