<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Upload\Utils\Cron;

/** Port of server/src/utils/__tests__/cron.test.ts. */
final class CronTest extends TestCase
{
    public function testGetWeeklyCronScheduleAt(): void
    {
        // it's a friday
        $date = new \DateTimeImmutable('2022-07-22T15:43:40.036');

        self::assertSame('40 43 15 * * 5', Cron::getWeeklyCronScheduleAt($date));
    }
}
