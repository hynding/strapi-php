<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\Utils\TransferWebsocketJson;

/**
 * Port of src/utils/__tests__/transfer-websocket-json.test.ts. BigInt, enumerable `toJSON` on a
 * spread root and Uint8Array have no PHP equivalent; the cases that do are kept.
 */
final class TransferWebsocketJsonTest extends TestCase
{
    public function testCircularNestedDataThrowsTypeErrorWithContext(): void
    {
        $cyclic = new \stdClass();
        $cyclic->tag = 'x';
        $cyclic->self = $cyclic;

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessageMatches('/could not be serialized to JSON/');
        TransferWebsocketJson::stringifyTransferWebSocketPayload(['uuid' => 'u', 'type' => 'transfer', 'data' => $cyclic]);
    }

    public function testUploadLikeNestedMetadataSerializes(): void
    {
        $s = TransferWebsocketJson::stringifyTransferWebSocketPayload([
            'uuid' => 'u',
            'type' => 'transfer',
            'kind' => 'step',
            'action' => 'stream',
            'step' => 'assets',
            'data' => [
                'action' => 'start',
                'assetID' => 'file-48',
                'metadata' => [
                    'name' => 'café 文件 🗂️.bin',
                    'caption' => null,
                    'alternativeText' => '',
                    'provider_metadata' => ['foo' => ['bar' => [1, 2, 3]]],
                ],
            ],
        ]);

        $roundTrip = json_decode($s, true);
        self::assertIsArray($roundTrip);
        self::assertStringContainsString('café', $roundTrip['data']['metadata']['name']);
    }

    public function testNestedJsonSerializableUsesItsJsonForm(): void
    {
        $inner = new class () implements \JsonSerializable {
            /** @return array<string, mixed> */
            public function jsonSerialize(): array
            {
                return ['id' => 48, 'note' => 'orm-shaped'];
            }
        };

        $s = TransferWebsocketJson::stringifyTransferWebSocketPayload(['uuid' => 'u', 'type' => 'transfer', 'data' => ['file' => $inner]]);
        self::assertStringContainsString('"note":"orm-shaped"', $s);
    }

    public function testErrorsSerializeAsEmptyObjectsAndDatesAsIsoStrings(): void
    {
        $s = TransferWebsocketJson::stringifyTransferWebSocketPayload([
            'uuid' => 'u',
            'error' => new \RuntimeException('boom'),
            'at' => new \DateTimeImmutable('2024-05-06T07:08:09.123Z'),
        ]);

        self::assertSame('{"uuid":"u","error":{},"at":"2024-05-06T07:08:09.123Z"}', $s);
    }
}
