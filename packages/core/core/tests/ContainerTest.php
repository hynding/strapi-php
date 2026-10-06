<?php

declare(strict_types=1);

namespace Strapi\Core\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Container;

final class ContainerTest extends TestCase
{
    public function testResolversAreInvokedOnceAndMemoized(): void
    {
        $container = new Container();
        $calls = 0;
        $container->add('service', static function () use (&$calls): object {
            $calls++;

            return new \stdClass();
        });

        self::assertTrue($container->has('service'));
        $a = $container->get('service');
        $b = $container->get('service');

        self::assertSame($a, $b);
        self::assertSame(1, $calls);
    }

    public function testPlainValuesAreStoredAsIs(): void
    {
        $container = new Container();
        $container->add('config', ['a' => 1]);

        self::assertSame(['a' => 1], $container->get('config'));
        self::assertFalse($container->has('missing'));
    }

    public function testCannotRegisterTheSameNameTwice(): void
    {
        $container = new Container();
        $container->add('x', 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot register already registered service x');
        $container->add('x', 2);
    }

    public function testGetUnknownServiceThrows(): void
    {
        $container = new Container();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not resolve service missing');
        $container->get('missing');
    }
}
