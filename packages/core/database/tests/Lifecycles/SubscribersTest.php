<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Lifecycles;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Lifecycles\Subscribers\ModelsLifecycles;
use Strapi\Database\Lifecycles\Subscribers\Subscribers;

/** lifecycles/subscribers/index.ts (no upstream unit test). */
final class SubscribersTest extends TestCase
{
    public function testIsValidSubscriber(): void
    {
        self::assertTrue(Subscribers::isValidSubscriber(static function (): void {
        }));
        self::assertTrue(Subscribers::isValidSubscriber(['models' => ['api::a.a'], 'afterCreate' => static function (): void {
        }]));
        self::assertTrue(Subscribers::isValidSubscriber(new ModelsLifecycles()));
        self::assertFalse(Subscribers::isValidSubscriber(null));
        self::assertFalse(Subscribers::isValidSubscriber('subscriber'));
    }
}
