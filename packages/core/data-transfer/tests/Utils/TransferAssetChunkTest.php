<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\Utils\TransferAssetChunk;
use Strapi\DataTransfer\Utils\TransferWebsocketJson;

/**
 * Port of src/utils/__tests__/transfer-asset-chunk.test.ts. PHP has no Buffer/Uint8Array: bytes
 * are strings, which travel as base64 (the replacer's Uint8Array case).
 */
final class TransferAssetChunkTest extends TestCase
{
    private const string BYTES = "\xde\xad\xbe\xef";

    /** @return array<string, mixed> */
    private static function wire(mixed $item): array
    {
        $decoded = json_decode(TransferWebsocketJson::stringifyTransferWebSocketPayload(['item' => $item]), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['item']);

        return $decoded['item'];
    }

    public function testCreateAndDecodeRoundTrip(): void
    {
        $item = TransferAssetChunk::createTransferAssetStreamChunk('asset-1', self::BYTES);
        self::assertSame('stream', $item['action']);
        self::assertSame('asset-1', $item['assetID']);
        self::assertSame('base64', $item['encoding']);
        self::assertSame(self::BYTES, TransferAssetChunk::decodeTransferAssetStreamItem($item));
    }

    public function testDecodeItemLegacyBufferJsonItem(): void
    {
        $item = ['action' => 'stream', 'assetID' => 'a', 'data' => ['type' => 'Buffer', 'data' => [0xde, 0xad, 0xbe, 0xef]]];
        self::assertSame(self::BYTES, TransferAssetChunk::decodeTransferAssetStreamItem($item));
    }

    public function testDecodeDataExplicitEncodingBase64(): void
    {
        self::assertSame(self::BYTES, TransferAssetChunk::decodeTransferAssetStreamData(base64_encode(self::BYTES), 'base64'));
    }

    public function testDecodeDataLegacyBufferJsonShape(): void
    {
        self::assertSame(self::BYTES, TransferAssetChunk::decodeTransferAssetStreamData(['type' => 'Buffer', 'data' => [0xde, 0xad, 0xbe, 0xef]]));
    }

    public function testDecodeDataBase64StringWithoutEncodingFlag(): void
    {
        self::assertSame(self::BYTES, TransferAssetChunk::decodeTransferAssetStreamData(base64_encode(self::BYTES)));
    }

    public function testWireCompatibilityStreamItemLegacyJsonShapeSurvivesParse(): void
    {
        $wire = self::wire(['action' => 'stream', 'assetID' => 'a', 'data' => ['type' => 'Buffer', 'data' => [0xde, 0xad, 0xbe, 0xef]]]);
        self::assertSame(self::BYTES, TransferAssetChunk::decodeTransferAssetStreamData($wire['data']));
    }

    public function testWireCompatibilityExplicitPullShape(): void
    {
        $wire = self::wire(['action' => 'stream', 'assetID' => 'a', 'encoding' => 'base64', 'data' => base64_encode(self::BYTES)]);
        self::assertSame(self::BYTES, TransferAssetChunk::decodeTransferAssetStreamData($wire['data'], $wire['encoding']));
    }

    public function testByteLengthOfBase64String(): void
    {
        $b64 = base64_encode(self::BYTES);
        self::assertSame(intdiv(strlen($b64) * 3, 4), TransferAssetChunk::transferAssetStreamChunkByteLength(['action' => 'stream', 'encoding' => 'base64', 'data' => $b64]));
        self::assertSame(intdiv(strlen($b64) * 3, 4), TransferAssetChunk::transferAssetStreamChunkByteLength(['action' => 'stream', 'data' => $b64]));
    }

    public function testByteLengthOfNonStream(): void
    {
        self::assertSame(0, TransferAssetChunk::transferAssetStreamChunkByteLength(['action' => 'end']));
    }

    public function testDecodeDataEncodingBase64WithLegacyObjectFallsBack(): void
    {
        self::assertSame(self::BYTES, TransferAssetChunk::decodeTransferAssetStreamData(['type' => 'Buffer', 'data' => [0xde, 0xad, 0xbe, 0xef]], 'base64'));
    }

    public function testCreateLegacyProducesLegacyBufferJsonShape(): void
    {
        $item = TransferAssetChunk::createTransferAssetStreamChunkLegacy('asset-1', self::BYTES);
        self::assertSame(['action' => 'stream', 'assetID' => 'asset-1', 'data' => ['type' => 'Buffer', 'data' => [0xde, 0xad, 0xbe, 0xef]]], $item);
        self::assertArrayNotHasKey('encoding', $item);
        self::assertSame(self::BYTES, TransferAssetChunk::decodeTransferAssetStreamItem($item));
    }

    public function testCreateLegacySurvivesTheWsReplacerUntouched(): void
    {
        $wire = self::wire(TransferAssetChunk::createTransferAssetStreamChunkLegacy('a', self::BYTES));
        self::assertSame(['type' => 'Buffer', 'data' => [0xde, 0xad, 0xbe, 0xef]], $wire['data']);
    }

    public function testCreateLegacyRejectsNullChunks(): void
    {
        $this->expectException(\TypeError::class);
        TransferAssetChunk::createTransferAssetStreamChunkLegacy('a', null);
    }

    public function testDecodeItemEncodingBase64WithLegacyPayload(): void
    {
        $item = ['action' => 'stream', 'assetID' => 'a', 'encoding' => 'base64', 'data' => ['type' => 'Buffer', 'data' => [0xde, 0xad, 0xbe, 0xef]]];
        self::assertSame(self::BYTES, TransferAssetChunk::decodeTransferAssetStreamItem($item));
    }

    public function testByteLengthOfLegacyBufferJsonShape(): void
    {
        self::assertSame(4, TransferAssetChunk::transferAssetStreamChunkByteLength(['action' => 'stream', 'data' => ['type' => 'Buffer', 'data' => [0xde, 0xad, 0xbe, 0xef]]]));
    }
}
