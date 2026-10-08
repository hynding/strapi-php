<?php

declare(strict_types=1);

namespace Strapi\Generators\Tests\Plops\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Generators\Plops\Utils\GetFilePath;

/** Port of src/plops/utils/__tests__/get-file-path.test.ts. */
final class GetFilePathTest extends TestCase
{
    public function testWithDestinationSetAsApi(): void
    {
        self::assertSame('api/{{ api }}', GetFilePath::getFilePath('api'));
    }

    public function testWithDestinationSetAsNew(): void
    {
        self::assertSame('api/{{ id }}', GetFilePath::getFilePath('new'));
    }

    public function testWithDestinationSetAsPlugin(): void
    {
        self::assertSame('plugins/{{ plugin }}/server/src', GetFilePath::getFilePath('plugin'));
    }

    public function testWithDestinationSetAsRoot(): void
    {
        self::assertSame('.', GetFilePath::getFilePath('root'));
    }

    public function testWithEmptyDestinationString(): void
    {
        self::assertSame('api/{{ id }}', GetFilePath::getFilePath(''));
    }
}
