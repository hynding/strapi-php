<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Permission;
use Strapi\ContentManager\Tests\StubStrapi;

/** Port of server/src/services/__tests__/permission.test.ts. */
final class PermissionTest extends TestCase
{
    public function testRegisterPermissionsIncludesNonDisplayedContentTypesForExplorerReadOnly(): void
    {
        $strapi = StubStrapi::create();
        StubStrapi::setService($strapi, 'content-types', new class () {
            /** @return list<array<string, mixed>> */
            public function findAllContentTypes(): array
            {
                return [
                    ['uid' => 'api::article.article', 'isDisplayed' => true],
                    ['uid' => 'plugin::users-permissions.role', 'isDisplayed' => false],
                ];
            }
        });
        $actionProvider = new class () {
            /** @var list<list<array<string, mixed>>> */
            public array $calls = [];

            /** @param list<array<string, mixed>> $actions */
            public function registerMany(array $actions): void
            {
                $this->calls[] = $actions;
            }
        };
        StubStrapi::setService($strapi, 'admin::permission', new class ($actionProvider) {
            public function __construct(public readonly object $actionProvider)
            {
            }
        });

        (new Permission($strapi))->registerPermissions();

        $actions = $actionProvider->calls[0];
        $byUid = array_column($actions, null, 'uid');

        self::assertSame(['api::article.article', 'plugin::users-permissions.role'], $byUid['explorer.read']['subjects']);
        self::assertSame(['api::article.article'], $byUid['explorer.create']['subjects']);
    }
}
