<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Openapi\Assemblers\Document\QueryParamStyles;

/** Port of __tests__/query-param-styles.test.ts. */
final class QueryParamStylesTest extends TestCase
{
    public function testDoesNotTreatFiltersAsBracketExpandable(): void
    {
        $filtersSchema = [
            'type' => 'object',
            'additionalProperties' => true,
        ];

        self::assertFalse(QueryParamStyles::hasExpandableObjectProperties($filtersSchema));
        self::assertTrue(QueryParamStyles::shouldUseDeepObjectStyle('filters', $filtersSchema));
    }
}
