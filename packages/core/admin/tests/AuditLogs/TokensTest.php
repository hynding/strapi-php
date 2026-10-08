<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\AuditLogs;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\AuditLogs\Tokens;
use Strapi\Utils\Sessions;

/** Port of server/src/audit-logs/__tests__/tokens.test.ts. */
final class TokensTest extends TestCase
{
    /** @return array<string, callable> */
    private static function getTransformers(): array
    {
        $lifecycle = new class () {
            /** @var array<string, callable> */
            public array $transformers = [];

            public function registerEvent(string $name, callable $transform): void
            {
                $this->transformers[$name] = $transform;
            }
        };

        Tokens::registerTokenAuditEvents($lifecycle);

        return $lifecycle->transformers;
    }

    public function testRegistersTheFourTokenEvents(): void
    {
        $names = array_keys(self::getTransformers());
        sort($names);

        self::assertSame(['token.create', 'token.delete', 'token.regenerate', 'token.update'], $names);
    }

    public function testTokenCreateForACustomContentApiToken(): void
    {
        $transform = self::getTransformers()['token.create'];

        self::assertSame([
            'resource' => ['type' => 'content-api', 'id' => 3, 'name' => 'CI'],
            'details' => [
                'description' => 'deploys',
                'lifespan' => 604800000,
                'expiresAt' => Sessions::toISOString(1_800_000_000_000),
                'type' => 'custom',
                'permissions' => ['api::a.a.find', 'api::b.b.find'],
            ],
        ], $transform([
            'tokenId' => 3,
            'name' => 'CI',
            'kind' => 'content-api',
            'description' => 'deploys',
            'lifespan' => '604800000',
            'expiresAt' => 1_800_000_000_000,
            'type' => 'custom',
            'permissions' => ['api::b.b.find', 'api::a.a.find'],
        ]));
    }

    public function testTokenCreateForAnAdminTokenRecordsTheOwnerIdAndPermissionRefsOnly(): void
    {
        $shape = self::getTransformers()['token.create']([
            'tokenId' => 4,
            'name' => 'Bot',
            'kind' => 'admin',
            'adminUserOwner' => 7,
            'description' => null,
            'lifespan' => null,
            'expiresAt' => null,
            'permissions' => [['action' => 'admin::webhooks.read', 'subject' => null, 'properties' => []]],
        ]);

        self::assertEquals([
            'resource' => ['type' => 'admin', 'id' => 4, 'name' => 'Bot'],
            'details' => [
                'description' => null,
                'lifespan' => null,
                'expiresAt' => null,
                'adminUserOwner' => 7,
                'permissions' => [['action' => 'admin::webhooks.read', 'subject' => null, 'properties' => []]],
            ],
        ], $shape);
        self::assertDoesNotMatchRegularExpression('/email|accessKey|encryptedKey/', (string) json_encode($shape));
    }

    public function testTokenUpdateCarriesTheChanges(): void
    {
        $changes = ['name' => ['before' => 'a', 'after' => 'b']];

        self::assertSame([
            'resource' => ['type' => 'transfer', 'id' => 1, 'name' => 'b'],
            'details' => ['changes' => $changes],
        ], self::getTransformers()['token.update'](['tokenId' => 1, 'name' => 'b', 'kind' => 'transfer', 'changes' => $changes]));
    }

    public function testTokenDeleteHasNoDetailsUnlessTheTokenHasAnOwner(): void
    {
        $transform = self::getTransformers()['token.delete'];

        self::assertSame(['resource' => ['type' => 'content-api', 'id' => 1, 'name' => 'x']], $transform(['tokenId' => 1, 'name' => 'x', 'kind' => 'content-api']));
        self::assertSame([
            'resource' => ['type' => 'admin', 'id' => 2, 'name' => 'y'],
            'details' => ['adminUserOwner' => 9],
        ], $transform(['tokenId' => 2, 'name' => 'y', 'kind' => 'admin', 'adminUserOwner' => 9]));
    }

    public function testTokenRegenerateHasNoDetailsUnlessTheTokenHasAnOwner(): void
    {
        $transform = self::getTransformers()['token.regenerate'];

        self::assertSame(['resource' => ['type' => 'transfer', 'id' => 1, 'name' => 'x']], $transform(['tokenId' => 1, 'name' => 'x', 'kind' => 'transfer']));
        self::assertSame([
            'resource' => ['type' => 'admin', 'id' => 2, 'name' => 'y'],
            'details' => ['adminUserOwner' => 9],
        ], $transform(['tokenId' => 2, 'name' => 'y', 'kind' => 'admin', 'adminUserOwner' => 9]));
    }

    public function testGetTokenChangesReturnsAnEmptyObjectWhenNothingChangedWhateverThePermissionOrder(): void
    {
        self::assertSame([], Tokens::getTokenChanges(
            ['name' => 'a', 'description' => '', 'type' => 'custom', 'permissions' => ['x', 'y']],
            ['name' => 'a', 'description' => '', 'type' => 'custom', 'permissions' => ['y', 'x']],
        ));
    }

    public function testGetTokenChangesRecordsChangedScalarFieldsWithBeforeAndAfter(): void
    {
        self::assertSame([
            'name' => ['before' => 'a', 'after' => 'b'],
            'description' => ['before' => null, 'after' => 'd'],
        ], Tokens::getTokenChanges(
            ['name' => 'a', 'description' => null, 'type' => 'read-only'],
            ['name' => 'b', 'description' => 'd', 'type' => 'read-only'],
        ));
    }

    public function testGetTokenChangesRecordsPermissionChangesAsSortedLists(): void
    {
        self::assertSame([
            'permissions' => [
                'before' => [['action' => 'b', 'subject' => null, 'properties' => []]],
                'after' => [
                    ['action' => 'a', 'subject' => 'api::x.x', 'properties' => ['fields' => ['title']]],
                    ['action' => 'c', 'subject' => null, 'properties' => []],
                ],
            ],
        ], Tokens::getTokenChanges(
            ['permissions' => [['action' => 'b', 'subject' => null, 'properties' => []]]],
            ['permissions' => [
                ['action' => 'c', 'subject' => null, 'properties' => []],
                ['action' => 'a', 'subject' => 'api::x.x', 'properties' => ['fields' => ['title']]],
            ]],
        ));
    }

    public function testToAdminPermissionRefsKeepsActionSubjectAndPropertiesOnly(): void
    {
        self::assertSame(
            [['action' => 'admin::webhooks.read', 'subject' => null, 'properties' => ['fields' => ['a']]]],
            Tokens::toAdminPermissionRefs([[
                'id' => 12,
                'action' => 'admin::webhooks.read',
                'properties' => ['fields' => ['a']],
                'conditions' => ['admin::is-creator'],
                'actionParameters' => [],
            ]]),
        );
    }

    public function testToAdminPermissionRefsToleratesAMissingRelation(): void
    {
        self::assertSame([], Tokens::toAdminPermissionRefs(null));
    }
}
