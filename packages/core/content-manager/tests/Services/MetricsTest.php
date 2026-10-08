<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Metrics;
use Strapi\ContentManager\Tests\StubStrapi;

/** Port of server/src/services/__tests__/metrics.test.ts. */
final class MetricsTest extends TestCase
{
    private const array CONTENT_TYPE = [
        'attributes' => [
            'field1' => ['type' => 'relation', 'relation' => 'oneToMany'],
            'field2' => ['type' => 'relation', 'relation' => 'manyToMany'],
            'field3' => ['type' => 'relation', 'relation' => 'manyWay'],
            'field4' => ['type' => 'relation', 'relation' => 'manyToOne'],
            'field5' => ['type' => 'relation', 'relation' => 'oneWay'],
            'field6' => ['type' => 'relation', 'relation' => 'oneToOne'],
        ],
    ];

    /** @return list<array{list<string>, list<bool|int>}> */
    public static function listViewCases(): array
    {
        return [
            [['fieldA'], [false]],
            [['fieldA', 'fieldB'], [false]],
            [['fieldA', 'field1'], [true, 2, 1]],
            [['field1', 'field2'], [true, 2, 2]],
            [['field1'], [true, 1, 1]],
            [['fieldA', 'fieldB', 'field1', 'field2'], [true, 4, 2]],
            [['fieldA', 'fieldB', 'field3', 'field4'], [true, 4, 2]],
            [['fieldA', 'fieldB', 'field5', 'field6'], [true, 4, 2]],
        ];
    }

    /**
     * @param list<string> $list
     * @param list<bool|int> $expectedResult
     */
    #[DataProvider('listViewCases')]
    public function testSendDidConfigureListView(array $list, array $expectedResult): void
    {
        $strapi = StubStrapi::create();
        $telemetry = new class () {
            /** @var list<array{string, array<string, mixed>}> */
            public array $calls = [];

            /** @param array<string, mixed> $payload */
            public function send(string $event, array $payload = []): bool
            {
                $this->calls[] = [$event, $payload];

                return true;
            }
        };
        $strapi->set('telemetry', $telemetry);

        (new Metrics($strapi))->sendDidConfigureListView(self::CONTENT_TYPE, ['layouts' => ['list' => $list]]);

        $eventProperties = ['containsRelationalFields' => $expectedResult[0]];
        if ($expectedResult[0]) {
            $eventProperties['displayedFields'] = $expectedResult[1];
            $eventProperties['displayedRelationalFields'] = $expectedResult[2];
        }

        self::assertCount(1, $telemetry->calls);
        self::assertSame(['didConfigureListView', ['eventProperties' => $eventProperties]], $telemetry->calls[0]);
    }
}
