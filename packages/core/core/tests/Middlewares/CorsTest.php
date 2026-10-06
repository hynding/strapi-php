<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Middlewares;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Middlewares\Cors;

/** Port of packages/core/core/src/middlewares/__tests__/cors.test.ts (`matchOrigin`). */
final class CorsTest extends TestCase
{
    public function testExactStringOriginMatching(): void
    {
        self::assertSame('https://example.com:3000', Cors::matchOrigin('https://example.com:3000', 'https://example.com:3000'));
        self::assertSame('', Cors::matchOrigin('https://example.com:3001', 'https://example.com:3000'));
        self::assertSame('', Cors::matchOrigin('http://example.com:3000', 'https://example.com:3000'));
        self::assertSame('', Cors::matchOrigin('https://api.example.com:3000', 'https://example.com:3000'));
        self::assertSame('', Cors::matchOrigin('https://otherdomain.com:3000', 'https://example.com:3000'));
    }

    public function testArrayOfOrigins(): void
    {
        $allowed = ['https://example.com:3000', 'https://api.example.com:3000'];
        self::assertSame('https://example.com:3000', Cors::matchOrigin('https://example.com:3000', $allowed));
        self::assertSame('', Cors::matchOrigin('https://otherdomain.com:3000', $allowed));
        self::assertSame('', Cors::matchOrigin('https://example.com:3001', $allowed));
    }

    public function testCommaSeparatedStringOfOrigins(): void
    {
        $allowed = 'https://example.com:3000, https://api.example.com:3000';
        self::assertSame('https://example.com:3000', Cors::matchOrigin('https://example.com:3000', $allowed));
        self::assertSame('', Cors::matchOrigin('https://otherdomain.com:3000', $allowed));
        self::assertSame('https://api.example.com:3000', Cors::matchOrigin('https://api.example.com:3000', 'https://example.com:3000 ,  https://api.example.com:3000  '));
        self::assertSame('https://example.com:3000', Cors::matchOrigin('https://example.com:3000', 'https://example.com:3000'));
    }

    public function testFunctionBasedOrigin(): void
    {
        $allow = static fn ($ctx): string => 'https://example.com:3000';
        self::assertSame('https://example.com:3000', Cors::matchOrigin('https://example.com:3000', $allow));
        self::assertSame('', Cors::matchOrigin('https://otherdomain.com:3000', $allow));
    }

    public function testEdgeCases(): void
    {
        self::assertSame('*', Cors::matchOrigin(null, 'https://example.com:3000'));
        self::assertSame('*', Cors::matchOrigin('', 'https://example.com:3000'));
        self::assertSame('', Cors::matchOrigin('null', 'https://example.com:3000'));
        self::assertSame('null', Cors::matchOrigin('null', '*'));
        self::assertSame('https://example.com:3000', Cors::matchOrigin('https://example.com:3000', '*'));
        self::assertSame('https://example.com:3000', Cors::matchOrigin('https://example.com:3000', null));
        self::assertSame('', Cors::matchOrigin('https://example.com:3000', ''));
    }
}
