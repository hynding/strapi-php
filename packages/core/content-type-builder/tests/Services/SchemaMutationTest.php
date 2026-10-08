<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Services\SchemaMutation;

/** Port of server/src/services/__tests__/schema-mutation.test.ts. */
final class SchemaMutationTest extends TestCase
{
    public function testContinuesCompensationAfterEarlierFailures(): void
    {
        $calls = new \ArrayObject();

        $builder = new class ($calls) {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls)
            {
            }

            public function rollback(): void
            {
                $this->calls[] = 'schema';
                throw new \RuntimeException('schema rollback failed');
            }
        };

        $apiHandler = new class ($calls) {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls)
            {
            }

            public function rollback(string $uid): void
            {
                $this->calls[] = "api:{$uid}";
                if ($uid === 'api::first.first') {
                    throw new \RuntimeException('first API rollback failed');
                }
            }

            public function clearGenerated(string $apiName): void
            {
                $this->calls[] = "generated:{$apiName}";
            }

            public function finalize(): void
            {
            }
        };

        try {
            SchemaMutation::rollbackSchemaMutation([
                'builder' => $builder,
                'apiHandler' => $apiHandler,
                'backedUpApiUids' => ['api::first.first', 'api::second.second'],
                'generatedApiNames' => ['created-before-schema-dir'],
            ]);
            self::fail('Expected the first compensation error');
        } catch (\RuntimeException $error) {
            self::assertSame('schema rollback failed', $error->getMessage());
        }

        self::assertSame([
            'generated:created-before-schema-dir',
            'schema',
            'api:api::first.first',
            'api:api::second.second',
        ], $calls->getArrayCopy());
    }
}
