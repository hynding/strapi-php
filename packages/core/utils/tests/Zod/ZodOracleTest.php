<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests\Zod;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodError;
use Strapi\Utils\Zod\ZodRegistry;
use Strapi\Utils\Zod\ZodType;

/**
 * Compares safeParse() with the results zod 4.4.3 itself produced for the same schema and input
 * (see oracle/cases.js): success, output data, and every issue's code, path, message and
 * code-specific fields, in zod's key order.
 */
final class ZodOracleTest extends TestCase
{
    /**
     * @param \Closure(): ZodType $factory
     * @param array<string, mixed> $expected
     */
    #[DataProviderExternal(ZodOracleCases::class, 'cases')]
    public function testMatchesZod(\Closure $factory, mixed $input, array $expected): void
    {
        $result = $factory()->safeParse($input);

        if ($expected['success']) {
            self::assertTrue($result['success'], 'expected success, got ' . ($result['error']?->getMessage() ?? ''));
            self::assertSame($expected['data'], $result['data']);

            return;
        }

        self::assertFalse($result['success'], 'expected failure, got ' . var_export($result['data'], true));
        self::assertInstanceOf(ZodError::class, $result['error']);
        self::assertSame(
            json_encode($expected['issues'], JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION),
            json_encode($result['error']->issues, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION),
        );
        self::assertSame($expected['issues'], $result['error']->issues);
    }

    /**
     * toJSONSchema() is best effort; compared with zod's output ignoring key order.
     *
     * @param \Closure(): (ZodType|ZodRegistry) $factory
     * @param \Closure(): array<string, mixed> $params
     */
    #[DataProviderExternal(ZodOracleCases::class, 'jsonSchemaCases')]
    public function testJsonSchemaMatchesZod(\Closure $factory, \Closure $params, string $expected): void
    {
        $actual = json_decode((string) json_encode(z::toJSONSchema($factory(), $params())), true);

        self::assertEquals(json_decode($expected, true), $actual);
    }
}
