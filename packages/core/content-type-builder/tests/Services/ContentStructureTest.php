<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Services\ContentStructure;
use Strapi\ContentTypeBuilder\Tests\StubStrapi;
use Strapi\Utils\Errors\ApplicationError;

require_once __DIR__ . '/../StubStrapi.php';

/**
 * Port of server/src/services/__tests__/content-structure.test.ts.
 *
 * Not ported: "persists and reloads a mixed folder deletion as a local delete with an ungrouped
 * plugin", which drives the admin panel's DataManager reducer (JavaScript, not part of this port).
 */
final class ContentStructureTest extends TestCase
{
    /** @var object{cleaned: array<string, mixed>|null, getCleanedFileCalls: int, writes: list<array<string, mixed>>} */
    private object $core;

    protected function setUp(): void
    {
        $this->core = new class {
            /** @var array<string, mixed>|null */
            public ?array $cleaned = null;

            public int $getCleanedFileCalls = 0;

            /** @var list<array<string, mixed>> */
            public array $writes = [];

            /** @return array<string, mixed>|null */
            public function getCleanedFile(): ?array
            {
                $this->getCleanedFileCalls++;

                return $this->cleaned;
            }

            /** @param array<string, mixed> $structure */
            public function write(array $structure): void
            {
                $this->writes[] = $structure;
            }
        };
    }

    /** @param array<string, string> $kinds uid => kind */
    private function service(array $kinds = []): ContentStructure
    {
        $strapi = StubStrapi::create();
        $strapi->set('content-structure', $this->core);
        $strapi->set('logger', new \Psr\Log\NullLogger());
        StubStrapi::addContentTypes($strapi, array_map(static fn (string $kind): array => ['kind' => $kind], $kinds));

        return ContentStructure::createContentStructureService($strapi);
    }

    /** @return array<string, mixed> */
    private static function validExample(): array
    {
        return [
            'version' => 1,
            'sections' => [
                'collectionTypes' => [
                    'groups' => [
                        [
                            'id' => 'grp_marketing',
                            'name' => 'Marketing',
                            'parent' => null,
                            'children' => [
                                ['type' => 'contentType', 'uid' => 'api::article.article'],
                                ['type' => 'group', 'id' => 'grp_blog'],
                                ['type' => 'contentType', 'uid' => 'api::category.category'],
                            ],
                        ],
                        [
                            'id' => 'grp_blog',
                            'name' => 'Blog',
                            'parent' => 'grp_marketing',
                            'children' => [['type' => 'contentType', 'uid' => 'api::author.author']],
                        ],
                    ],
                ],
                'singleTypes' => ['groups' => []],
            ],
        ];
    }

    /** @return array<string, string> */
    private static function exampleEffectiveSet(): array
    {
        return [
            'api::article.article' => 'collectionType',
            'api::category.category' => 'collectionType',
            'api::author.author' => 'collectionType',
        ];
    }

    /** @param list<array<string, mixed>> $children */
    private static function single(string $section, array $children): array
    {
        $groups = [['id' => 'grp_a', 'name' => 'A', 'parent' => null, 'children' => $children]];

        return [
            'version' => 1,
            'sections' => [
                'collectionTypes' => ['groups' => $section === 'collectionTypes' ? $groups : []],
                'singleTypes' => ['groups' => $section === 'singleTypes' ? $groups : []],
            ],
        ];
    }

    private static function message(\Closure $fn): ?string
    {
        try {
            $fn();
        } catch (ApplicationError $error) {
            return $error->getMessage();
        }

        return null;
    }

    public function testValidateContentTypeUidReferences(): void
    {
        $service = $this->service();

        // accepts a structure whose references all exist with matching kinds
        self::assertNull(self::message(static fn () => $service->validateContentTypeUidReferences(self::validExample(), self::exampleEffectiveSet())));

        // ignores group children (only content-type references are checked)
        $file = [
            'version' => 1,
            'sections' => [
                'collectionTypes' => ['groups' => [
                    ['id' => 'grp_a', 'name' => 'A', 'parent' => null, 'children' => [['type' => 'group', 'id' => 'grp_b']]],
                    ['id' => 'grp_b', 'name' => 'B', 'parent' => 'grp_a', 'children' => []],
                ]],
                'singleTypes' => ['groups' => []],
            ],
        ];
        self::assertNull(self::message(static fn () => $service->validateContentTypeUidReferences($file, [])));

        // rejects a uid absent from the effective content-type set
        $file = self::validExample();
        $file['sections']['collectionTypes']['groups'][1]['children'][] = ['type' => 'contentType', 'uid' => 'api::ghost.ghost'];
        self::assertMatchesRegularExpression('/api::ghost.ghost.*does not exist/', (string) self::message(static fn () => $service->validateContentTypeUidReferences($file, self::exampleEffectiveSet())));

        // accepts a uid created in the same batch
        $file = self::validExample();
        $file['sections']['collectionTypes']['groups'][1]['children'][] = ['type' => 'contentType', 'uid' => 'api::fresh.fresh'];
        self::assertNull(self::message(static fn () => $service->validateContentTypeUidReferences($file, [...self::exampleEffectiveSet(), 'api::fresh.fresh' => 'collectionType'])));

        // rejects a collectionType uid under singleTypes
        self::assertMatchesRegularExpression(
            '/cannot be placed in section "singleTypes"/',
            (string) self::message(static fn () => $service->validateContentTypeUidReferences(
                self::single('singleTypes', [['type' => 'contentType', 'uid' => 'api::article.article']]),
                ['api::article.article' => 'collectionType'],
            )),
        );
    }

    public function testAggregatesViolationsIntoOneApplicationError(): void
    {
        $file = self::validExample();
        $file['sections']['collectionTypes']['groups'][0]['children'][] = ['type' => 'contentType', 'uid' => 'api::ghost.ghost'];
        $file['sections']['collectionTypes']['groups'][1]['children'][] = ['type' => 'contentType', 'uid' => 'api::phantom.phantom'];

        try {
            $this->service()->validateContentTypeUidReferences($file, self::exampleEffectiveSet());
            self::fail('Expected an ApplicationError');
        } catch (ApplicationError $error) {
            self::assertMatchesRegularExpression('/api::ghost.ghost/', $error->getMessage());
            self::assertMatchesRegularExpression('/api::phantom.phantom/', $error->getMessage());
            self::assertGreaterThanOrEqual(2, count($error->details['errors']));
        }
    }

    public function testValidateFromUpdate(): void
    {
        $service = $this->service(self::exampleEffectiveSet());

        // does not throw for a valid structure and never touches disk
        $service->validateFromUpdate(['incomingStructure' => self::validExample(), 'upsertedUids' => [], 'deletedUids' => []]);
        // does not throw for a reference to a uid deleted in the same batch (pruned first)
        $service->validateFromUpdate(['incomingStructure' => self::validExample(), 'upsertedUids' => [], 'deletedUids' => ['api::category.category']]);
        // is a no-op when no incoming structure is provided
        $service->validateFromUpdate(['upsertedUids' => [], 'deletedUids' => ['api::category.category']]);

        self::assertSame(0, $this->core->getCleanedFileCalls);
        self::assertSame([], $this->core->writes);
    }

    public function testValidateFromUpdateThrowsForANonexistentContentType(): void
    {
        $service = $this->service(['api::article.article' => 'collectionType']);

        self::assertMatchesRegularExpression('/api::ghost.ghost.*does not exist/', (string) self::message(static fn () => $service->validateFromUpdate([
            'incomingStructure' => self::single('collectionTypes', [['type' => 'contentType', 'uid' => 'api::ghost.ghost']]),
            'upsertedUids' => [],
            'deletedUids' => [],
        ])));
        self::assertSame([], $this->core->writes);
    }

    public function testValidateFromUpdateAcceptsAKindSwitchedInTheSameBatch(): void
    {
        $this->service(['api::article.article' => 'collectionType'])->validateFromUpdate([
            'incomingStructure' => self::single('singleTypes', [['type' => 'contentType', 'uid' => 'api::article.article']]),
            'upsertedUids' => ['api::article.article' => 'singleType'],
            'deletedUids' => [],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testValidateFromUpdateRejectsAKindMismatchedReference(): void
    {
        $service = $this->service(['api::article.article' => 'singleType']);

        self::assertMatchesRegularExpression('/cannot be placed in section "collectionTypes"/', (string) self::message(static fn () => $service->validateFromUpdate([
            'incomingStructure' => self::single('collectionTypes', [['type' => 'contentType', 'uid' => 'api::article.article']]),
            'upsertedUids' => [],
            'deletedUids' => [],
        ])));
        self::assertSame([], $this->core->writes);
    }

    public function testCommitPersistsAValidStructureUnchanged(): void
    {
        self::assertTrue($this->service(self::exampleEffectiveSet())->commitFromUpdate(['incomingStructure' => self::validExample(), 'deletedUids' => []]));

        self::assertCount(1, $this->core->writes);
        $written = $this->core->writes[0];
        self::assertSame([
            ['type' => 'contentType', 'uid' => 'api::article.article'],
            ['type' => 'group', 'id' => 'grp_blog'],
            ['type' => 'contentType', 'uid' => 'api::category.category'],
        ], $written['sections']['collectionTypes']['groups'][0]['children']);
        self::assertSame([['type' => 'contentType', 'uid' => 'api::author.author']], $written['sections']['collectionTypes']['groups'][1]['children']);
    }

    public function testCommitPrunesUidsDeletedInTheSameBatch(): void
    {
        self::assertTrue($this->service(self::exampleEffectiveSet())->commitFromUpdate(['incomingStructure' => self::validExample(), 'deletedUids' => ['api::category.category']]));

        $children = $this->core->writes[0]['sections']['collectionTypes']['groups'][0]['children'];
        self::assertNotContains(['type' => 'contentType', 'uid' => 'api::category.category'], $children);
        self::assertContains(['type' => 'contentType', 'uid' => 'api::article.article'], $children);
    }

    public function testCommitWithoutIncomingStructurePrunesTheCurrentFile(): void
    {
        $this->core->cleaned = self::validExample();

        self::assertTrue($this->service(self::exampleEffectiveSet())->commitFromUpdate(['deletedUids' => ['api::category.category']]));

        self::assertSame(1, $this->core->getCleanedFileCalls);
        self::assertCount(1, $this->core->writes);
        self::assertNotContains(['type' => 'contentType', 'uid' => 'api::category.category'], $this->core->writes[0]['sections']['collectionTypes']['groups'][0]['children']);
    }

    public function testCommitWithoutIncomingStructureNorDeletionsReturnsFalse(): void
    {
        self::assertFalse($this->service()->commitFromUpdate(['deletedUids' => []]));
        self::assertSame(0, $this->core->getCleanedFileCalls);
        self::assertSame([], $this->core->writes);
    }

    public function testCommitDoesNotRevalidate(): void
    {
        // A reference validateFromUpdate would have rejected still writes here: commit is a pure
        // persist step, so validation must gate it upstream (which updateSchema does).
        self::assertTrue($this->service(['api::article.article' => 'collectionType'])->commitFromUpdate([
            'incomingStructure' => self::single('collectionTypes', [['type' => 'contentType', 'uid' => 'api::ghost.ghost']]),
            'deletedUids' => [],
        ]));
        self::assertCount(1, $this->core->writes);
    }

    public function testCommitNeverReloads(): void
    {
        $this->core->cleaned = self::validExample();
        $service = $this->service(self::exampleEffectiveSet());
        $reloader = \Strapi\Core\Core::instance()?->reload();
        self::assertNotNull($reloader);
        $reloader->setWatching(false);

        $service->commitFromUpdate(['incomingStructure' => self::validExample(), 'deletedUids' => ['api::category.category']]);

        // restart policy belongs to the controller: the service only writes, the reloader is untouched
        self::assertCount(1, $this->core->writes);
        self::assertFalse($reloader->isWatching());
    }
}
