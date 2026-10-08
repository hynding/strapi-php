<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Strapi;

use Strapi\DataTransfer\Strapi\TransferPolicy;
use Strapi\DataTransfer\Tests\BootedAppTestCase;

/**
 * Port of src/strapi/__tests__/transfer-policy.test.ts, against the fixture app instead of a mocked
 * `strapi`: api::article.article has `permissions` (→ admin::permission), the `shared.block`
 * component (with its own `permissions`) and the `blocks` dynamic zone; plugin::upload.file has the
 * `related` morph relation.
 */
final class TransferPolicyTest extends BootedAppTestCase
{
    public function testKeepsRemoteAdminProtectionDistinctFromOfficialCliExclusions(): void
    {
        self::assertTrue(TransferPolicy::isProtectedRemotePushType('admin::future-model'));
        self::assertFalse(TransferPolicy::isProtectedRemotePushType('plugin::content-releases.release'));
        self::assertTrue(TransferPolicy::isIgnoredOfficialTransferType('admin::user'));
        self::assertTrue(TransferPolicy::isIgnoredOfficialTransferType('plugin::content-releases.release'));
        self::assertFalse(TransferPolicy::isIgnoredOfficialTransferType('api::article.article'));
    }

    public function testNormalizesProtectedRestoreTypesWithoutMutatingOtherRestoreOptions(): void
    {
        $restore = [
            'assets' => false,
            'configuration' => ['coreStore' => false, 'webhook' => true],
            'entities' => [
                'include' => ['api::article.article', 'admin::user'],
                'exclude' => ['plugin::upload.file'],
                'filters' => [static fn (): bool => true],
                'params' => ['api::article.article' => ['locale' => 'en']],
            ],
        ];

        $normalized = TransferPolicy::normalizeRemoteRestoreOptions(self::strapi(), $restore);

        self::assertFalse($normalized['assets']);
        self::assertSame(['coreStore' => false, 'webhook' => true], $normalized['configuration']);
        self::assertSame(['api::article.article'], $normalized['entities']['include']);
        self::assertSame(['api::article.article' => ['locale' => 'en']], $normalized['entities']['params']);
        self::assertArrayNotHasKey('filters', $normalized['entities']);
        foreach (['plugin::upload.file', 'admin::user', 'admin::permission'] as $uid) {
            self::assertContains($uid, $normalized['entities']['exclude']);
        }

        // the input is untouched
        self::assertSame(['api::article.article', 'admin::user'], $restore['entities']['include']);
        self::assertSame(['plugin::upload.file'], $restore['entities']['exclude']);
        self::assertCount(1, $restore['entities']['filters']);
    }

    public function testRejectsProtectedEntityRootsAndEitherProtectedLinkEndpoint(): void
    {
        self::assertThrowsMatching('/admin::user/', static fn () => TransferPolicy::assertRemoteEntityAllowed(self::strapi(), ['type' => 'admin::user', 'id' => 1, 'data' => []]));
        self::assertThrowsMatching('/admin::user/', static fn () => TransferPolicy::assertRemoteLinkAllowed([
            'kind' => 'relation.basic',
            'relation' => 'oneToOne',
            'left' => ['type' => 'api::article.article', 'ref' => 1, 'field' => 'author'],
            'right' => ['type' => 'admin::user', 'ref' => 1],
        ]));
        self::assertThrowsMatching('/admin::role/', static fn () => TransferPolicy::assertRemoteLinkAllowed([
            'kind' => 'relation.basic',
            'relation' => 'oneToOne',
            'left' => ['type' => 'admin::role', 'ref' => 1, 'field' => 'users'],
            'right' => ['type' => 'api::article.article', 'ref' => 1],
        ]));
    }

    public function testRejectsProtectedStaticAndNestedComponentRelationPayloadsWithoutRejectingOrdinaryFields(): void
    {
        $assert = static fn (array $data) => static fn () => TransferPolicy::assertRemoteEntityAllowed(self::strapi(), ['type' => 'api::article.article', 'id' => 1, 'data' => $data]);

        self::assertThrowsMatching('/admin::permission/', $assert(['permissions' => [1]]));
        self::assertThrowsMatching('/admin::permission/', $assert(['block' => ['permissions' => [1]]]));
        self::assertThrowsMatching('/admin::permission/', $assert(['blocks' => [['__component' => 'shared.block', 'permissions' => [1]]]]));

        $assert(['title' => 'ordinary transfer data'])();
    }

    public function testRejectsProtectedOwnerRelationJoinColumnAliasesFromDestinationMetadata(): void
    {
        $joinColumn = self::strapi()->db()->metadata->get('api::article.article')['attributes']['createdBy']['joinColumn']['name'] ?? null;
        self::assertIsString($joinColumn);

        self::assertThrowsMatching('/admin::user/', static fn () => TransferPolicy::assertRemoteEntityAllowed(self::strapi(), [
            'type' => 'api::article.article',
            'id' => 1,
            'data' => [$joinColumn => 1],
        ]));
    }

    public function testAcceptsTheCapturedOfficialCreatorPayloadWithoutCreatorRelationFields(): void
    {
        TransferPolicy::assertRemoteEntityAllowed(self::strapi(), [
            'type' => 'api::article.article',
            'id' => 1,
            'data' => [
                'title' => 'ordinary official source entry',
                'block' => null,
                'createdAt' => '2026-08-10T00:00:00.000Z',
                'updatedAt' => '2026-08-10T00:00:00.000Z',
                'publishedAt' => '2026-08-10T00:00:00.000Z',
                'documentId' => 'cms1198-document-id',
                'locale' => null,
            ],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testAcceptsDynamicZonePayloadsUsingTheirLogicalSchema(): void
    {
        TransferPolicy::assertRemoteEntityAllowed(self::strapi(), [
            'type' => 'api::article.article',
            'id' => 1,
            'data' => ['blocks' => [['__component' => 'shared.block']]],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testRejectsProtectedPolymorphicDiscriminatorsAndMalformedSuppliedDynamicZoneComponents(): void
    {
        self::assertThrowsMatching('/admin::user/', static fn () => TransferPolicy::assertRemoteEntityAllowed(self::strapi(), [
            'type' => 'plugin::upload.file',
            'id' => 1,
            'data' => ['related' => [['id' => 1, '__type' => 'admin::user']]],
        ]));
        self::assertThrowsMatching('/dynamic zone/i', static fn () => TransferPolicy::assertRemoteEntityAllowed(self::strapi(), [
            'type' => 'api::article.article',
            'id' => 1,
            'data' => ['blocks' => [['permissions' => [1]]]],
        ]));
    }

    private static function assertThrowsMatching(string $pattern, \Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            self::assertMatchesRegularExpression($pattern, $e->getMessage());

            return;
        }
        self::fail("Expected an exception matching {$pattern}");
    }
}
