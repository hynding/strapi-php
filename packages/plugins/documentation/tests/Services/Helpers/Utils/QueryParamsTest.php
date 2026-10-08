<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Tests\Services\Helpers\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\Documentation\Services\Helpers\Utils\QueryParams;

/** Port of server/src/services/helpers/utils/__tests__/query-params.test.ts. */
final class QueryParamsTest extends TestCase
{
    public function testDocumentsPopulateAsEitherAStringOrAnArrayOfStrings(): void
    {
        $populateParam = null;
        foreach (QueryParams::PARAMS as $param) {
            if ($param['name'] === 'populate') {
                $populateParam = $param;
            }
        }

        self::assertNotNull($populateParam);
        self::assertSame([
            'oneOf' => [
                ['type' => 'string'],
                [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ], $populateParam['schema']);
    }
}
