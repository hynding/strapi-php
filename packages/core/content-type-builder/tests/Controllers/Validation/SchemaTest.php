<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Controllers\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Controllers\Validation\Schema;
use Strapi\ContentTypeBuilder\Tests\StubStrapi;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Zod\ParsePayload;
use Strapi\Utils\Zod\Undefined;

require_once __DIR__ . '/../../StubStrapi.php';

/** Port of server/src/controllers/validation/__tests__/schema.test.ts. */
final class SchemaTest extends TestCase
{
    private ParsePayload $ctx;

    private int $issues = 0;

    protected function setUp(): void
    {
        $this->issues = 0;
        $this->ctx = new ParsePayload(null);
        $this->ctx->addIssueHandler = function (): void {
            $this->issues++;
        };
    }

    private function expectAddIssue(bool $shouldAddIssue): void
    {
        if ($shouldAddIssue) {
            self::assertGreaterThan(0, $this->issues);
        } else {
            self::assertSame(0, $this->issues);
        }

        // so it can be ran many time inside the same test
        $this->issues = 0;
    }

    /** @return array<string, mixed> */
    private static function schemaWithOptionalDefaults(bool $includeUndefined): array
    {
        return [
            'data' => [
                'components' => [
                    [
                        'action' => 'create',
                        'uid' => 'shared.hero',
                        'displayName' => 'Hero',
                        'category' => 'shared',
                        'attributes' => [],
                        ...($includeUndefined ? ['config' => Undefined::Value] : []),
                    ],
                ],
                'contentTypes' => [
                    [
                        'action' => 'create',
                        'uid' => 'api::article.article',
                        'displayName' => 'Article',
                        'draftAndPublish' => true,
                        'singularName' => 'article',
                        'pluralName' => 'articles',
                        'kind' => 'singleType',
                        'attributes' => [],
                        ...($includeUndefined ? ['options' => Undefined::Value, 'pluginOptions' => Undefined::Value] : []),
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function schemaWithSearchable(bool|null|Undefined $searchable = Undefined::Value): array
    {
        return [
            'data' => [
                'contentTypes' => [
                    [
                        'action' => 'create',
                        'uid' => 'api::article.article',
                        'displayName' => 'Article',
                        'draftAndPublish' => false,
                        'singularName' => 'article',
                        'pluralName' => 'articles',
                        'kind' => 'collectionType',
                        'attributes' => [
                            [
                                'action' => 'create',
                                'name' => 'secret',
                                'properties' => [
                                    'type' => 'text',
                                    'private' => true,
                                    ...($searchable === Undefined::Value ? [] : ['searchable' => $searchable]),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testReportsAMissingSchema(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Schema is required');
        Schema::validateUpdateSchema(null);
    }

    public function testReportsASchemaWithTheWrongType(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid schema, expected an object with a data property');
        Schema::validateUpdateSchema('invalid');
    }

    public function testAppliesCollectionDefaults(): void
    {
        self::assertSame(['data' => ['components' => [], 'contentTypes' => []]], Schema::validateUpdateSchema(['data' => []]));
        self::assertSame(
            ['data' => ['components' => [], 'contentTypes' => []]],
            Schema::validateUpdateSchema(['data' => ['components' => Undefined::Value, 'contentTypes' => Undefined::Value]]),
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function undefinedVariants(): iterable
    {
        yield 'absent' => [false];
        yield 'present with undefined' => [true];
    }

    #[DataProvider('undefinedVariants')]
    public function testAppliesNestedObjectDefaults(bool $includeUndefined): void
    {
        $result = Schema::validateUpdateSchema(self::schemaWithOptionalDefaults($includeUndefined));

        self::assertSame([], $result['data']['components'][0]['config']);
        self::assertSame([], $result['data']['contentTypes'][0]['options']);
        self::assertSame([], $result['data']['contentTypes'][0]['pluginOptions']);
    }

    public function testRetainsSearchable(): void
    {
        foreach ([false, true] as $searchable) {
            $result = Schema::validateUpdateSchema(self::schemaWithSearchable($searchable));
            self::assertSame(
                ['type' => 'text', 'private' => true, 'searchable' => $searchable],
                array_intersect_key($result['data']['contentTypes'][0]['attributes'][0]['properties'], array_flip(['type', 'private', 'searchable'])),
            );
        }
    }

    public function testAcceptsAnAbsentSearchableProperty(): void
    {
        $result = Schema::validateUpdateSchema(self::schemaWithSearchable());
        $properties = $result['data']['contentTypes'][0]['attributes'][0]['properties'];

        self::assertSame('text', $properties['type']);
        self::assertTrue($properties['private']);
        self::assertArrayNotHasKey('searchable', $properties);
    }

    public function testRejectsANullSearchableProperty(): void
    {
        $this->expectException(ValidationError::class);
        Schema::validateUpdateSchema(self::schemaWithSearchable(null));
    }

    public function testMaxLengthGreaterThanMinLength(): void
    {
        foreach ([[1, 2], [0, 1], [-1, 0], [1, 1], [0, 0], [-1, -1]] as [$min, $max]) {
            Schema::maxLengthGreaterThanMinLength(['minLength' => $min, 'maxLength' => $max], $this->ctx);
            $this->expectAddIssue(false);
        }
        foreach ([[2, 1], [1, 0], [0, -1]] as [$min, $max]) {
            Schema::maxLengthGreaterThanMinLength(['minLength' => $min, 'maxLength' => $max], $this->ctx);
            $this->expectAddIssue(true);
        }
    }

    public function testMaxGreaterThanMin(): void
    {
        foreach ([[1, 2], [0, 1], [-1, 0], [1, 1], [0, 0], [-1, -1]] as [$min, $max]) {
            Schema::maxGreaterThanMin(['min' => $min, 'max' => $max], $this->ctx);
            $this->expectAddIssue(false);
        }
        foreach ([[2, 1], [1, 0], [0, -1]] as [$min, $max]) {
            Schema::maxGreaterThanMin(['min' => $min, 'max' => $max], $this->ctx);
            $this->expectAddIssue(true);
        }
        foreach ([['max' => 1], ['min' => 1], ['max' => 1, 'min' => 'hello'], ['max' => 'hello', 'min' => 1]] as $value) {
            Schema::maxGreaterThanMin($value, $this->ctx);
            $this->expectAddIssue(false);
        }
    }

    public function testVerifyDraftAndPublishReservedAttributes(): void
    {
        $strapi = StubStrapi::create();
        StubStrapi::addContentTypes($strapi, [
            'api::reservation.reservation' => [
                'attributes' => [
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'enumeration', 'enum' => ['confirmed', 'canceled']],
                ],
            ],
        ]);

        // blocks enabling draft and publish when a status attribute exists
        Schema::verifyDraftAndPublishReservedAttributes([
            'action' => 'update',
            'uid' => 'api::reservation.reservation',
            'draftAndPublish' => true,
            'attributes' => [['action' => 'update', 'name' => 'status']],
        ], $this->ctx);
        $this->expectAddIssue(true);

        // allows enabling draft and publish when status is removed in the same update
        Schema::verifyDraftAndPublishReservedAttributes([
            'action' => 'update',
            'uid' => 'api::reservation.reservation',
            'draftAndPublish' => true,
            'attributes' => [['action' => 'delete', 'name' => 'status']],
        ], $this->ctx);
        $this->expectAddIssue(false);

        // allows draft and publish when no reserved attributes are present
        Schema::verifyDraftAndPublishReservedAttributes([
            'action' => 'create',
            'draftAndPublish' => true,
            'attributes' => [['action' => 'create', 'name' => 'title']],
        ], $this->ctx);
        $this->expectAddIssue(false);
    }
}
