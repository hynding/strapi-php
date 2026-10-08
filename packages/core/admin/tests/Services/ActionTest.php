<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Action;
use Strapi\Admin\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of server/src/services/__tests__/action.test.ts. */
final class ActionTest extends TestCase
{
    private const string AUTHOR_CODE = 'strapi-author';

    private const string PUBLISH_ACTION = 'plugin::content-manager.explorer.publish';

    /** @return list<array{actionId: string}> */
    private static function fixtures(): array
    {
        return [
            ['actionId' => 'test.action'],
            ['actionId' => self::PUBLISH_ACTION],
            ['actionId' => 'plugin::test.action'],
        ];
    }

    /** @param list<mixed> $findOneCalls */
    private static function strapi(?string $roleCode, array &$findOneCalls): Strapi
    {
        $strapi = StubStrapi::create();
        $actionProvider = new class () {
            /** @return list<array{actionId: string}> */
            public function values(): array
            {
                return ActionTest::publicFixtures();
            }
        };
        StubStrapi::setService($strapi, 'admin::permission', new class ($actionProvider) {
            public function __construct(public readonly object $actionProvider)
            {
            }
        });
        StubStrapi::setService($strapi, 'admin::role', new class ($roleCode, $findOneCalls) {
            /** @param list<mixed> $calls */
            public function __construct(private readonly ?string $code, private array &$calls)
            {
            }

            /** @param array<string, mixed> $params */
            public function findOne(array $params): ?array
            {
                $this->calls[] = $params;

                return ['code' => $this->code];
            }
        });

        return $strapi;
    }

    /** @return list<array{actionId: string}> */
    public static function publicFixtures(): array
    {
        return self::fixtures();
    }

    public function testReturnsEveryActionWithoutRole(): void
    {
        $calls = [];
        $actions = (new Action(self::strapi(null, $calls)))->getAllowedActionsForRole();

        self::assertSame(self::fixtures(), $actions);
    }

    public function testReturnsEveryActionIfRoleIsNotAuthor(): void
    {
        $calls = [];
        $actions = (new Action(self::strapi('custom-code ', $calls)))->getAllowedActionsForRole('1');

        self::assertSame([['id' => '1']], $calls);
        self::assertSame(self::fixtures(), $actions);
    }

    public function testExcludesPublishActionForAuthorRole(): void
    {
        $calls = [];
        $actions = (new Action(self::strapi(self::AUTHOR_CODE, $calls)))->getAllowedActionsForRole('1');

        self::assertSame([['id' => '1']], $calls);
        self::assertSame(array_values(array_filter(self::fixtures(), static fn (array $f): bool => $f['actionId'] !== self::PUBLISH_ACTION)), $actions);
    }
}
