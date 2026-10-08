<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Middlewares;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Middlewares\IsDevelopmentMode;
use Strapi\ContentTypeBuilder\Tests\StubStrapi;
use Strapi\Core\Services\Server\Context;
use Strapi\Utils\Errors\PolicyError;

require_once __DIR__ . '/../StubStrapi.php';

/** Port of server/src/middlewares/__tests__/is-development-mode.test.ts. */
final class IsDevelopmentModeTest extends TestCase
{
    private function invoke(mixed $autoReload, bool &$nextCalled): void
    {
        $strapi = StubStrapi::create();
        $strapi->config()->set('autoReload', $autoReload);
        $nextCalled = false;

        (new IsDevelopmentMode())(new Context(new ServerRequest('POST', '/')), static function () use (&$nextCalled): void {
            $nextCalled = true;
        });
    }

    public function testAllowsTheRequestWhenAutoReloadIsTrue(): void
    {
        $nextCalled = false;
        $this->invoke(true, $nextCalled);

        self::assertTrue($nextCalled);
    }

    /** @return iterable<string, array{mixed}> */
    public static function productionValues(): iterable
    {
        yield 'false (production mode)' => [false];
        yield 'null' => [null];
    }

    #[DataProvider('productionValues')]
    public function testThrowsAPolicyErrorWhenAutoReloadIsNotTrue(mixed $autoReload): void
    {
        $nextCalled = false;

        try {
            $this->invoke($autoReload, $nextCalled);
            self::fail('Expected a PolicyError');
        } catch (PolicyError $error) {
            self::assertStringContainsString('Content-Type Builder modifications are disabled in production mode', $error->getMessage());
        }

        self::assertFalse($nextCalled);
    }
}
