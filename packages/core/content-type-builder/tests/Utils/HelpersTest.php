<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Utils\Helpers;

/** Port of server/src/utils/__tests__/helpers.vitest.test.ts. */
final class HelpersTest extends TestCase
{
    public function testEscapeNewlines(): void
    {
        self::assertSame("line1\nline2\nline3", Helpers::escapeNewlines("line1\nline2\r\nline3"));
        self::assertSame('a | b', Helpers::escapeNewlines("a\nb", ' | '));
        self::assertSame('', Helpers::escapeNewlines());
    }

    public function testDeepTrimObject(): void
    {
        self::assertSame('hello', Helpers::deepTrimObject('  hello  '));
        self::assertSame(
            ['name' => 'Article', 'tags' => ['a', 'b'], 'meta' => ['title' => 'Hi']],
            Helpers::deepTrimObject(['name' => '  Article  ', 'tags' => ['  a ', ' b  '], 'meta' => ['title' => '  Hi  ']]),
        );
        self::assertSame(42, Helpers::deepTrimObject(42));
    }

    public function testDeepTrimObjectThrowsWhenGivenNull(): void
    {
        $this->expectException(\TypeError::class);
        Helpers::deepTrimObject(null);
    }
}
