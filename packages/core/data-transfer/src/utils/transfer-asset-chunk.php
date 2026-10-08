<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

/**
 * Port of src/utils/transfer-asset-chunk.ts: the wire shapes of asset bytes in the remote
 * transfer protocol (push and pull). Chunks are PHP binary strings.
 */
final class TransferAssetChunk
{
    /**
     * Canonical **outbound** asset chunk for WebSocket JSON (push and pull).
     * Base64 string `data` keeps `JSON.parse` heap bounded vs `{ type: 'Buffer', data: [n,…] }`.
     *
     * @return array{action: 'stream', assetID: string, encoding: 'base64', data: string}
     */
    public static function createTransferAssetStreamChunk(string $assetID, ?string $chunk): array
    {
        if ($chunk === null) {
            throw new \TypeError('Asset stream yielded a null/undefined chunk; refusing to encode (would trigger Buffer.from(undefined))');
        }

        return [
            'action' => 'stream',
            'assetID' => $assetID,
            'encoding' => 'base64',
            'data' => base64_encode($chunk),
        ];
    }

    /**
     * Legacy asset-chunk shape for remotes that pre-date #23479 and do `Buffer.from(item.data.data)`
     * in their push handler (`buffer.toJSON()`: `{ type: 'Buffer', data: number[] }`).
     *
     * @return array{action: 'stream', assetID: string, data: array{type: 'Buffer', data: list<int>}}
     */
    public static function createTransferAssetStreamChunkLegacy(string $assetID, ?string $chunk): array
    {
        if ($chunk === null) {
            throw new \TypeError('Asset stream yielded a null/undefined chunk; refusing to encode (would trigger Buffer.from(undefined))');
        }

        $bytes = $chunk === '' ? [] : array_map('ord', str_split($chunk));

        return [
            'action' => 'stream',
            'assetID' => $assetID,
            'data' => ['type' => 'Buffer', 'data' => $bytes],
        ];
    }

    /**
     * Decode a stream item from `TransferAssetFlow` after `JSON.parse` (shared by push + pull handlers
     * and the remote source provider).
     *
     * @param array<string, mixed> $item
     */
    public static function decodeTransferAssetStreamItem(array $item): string
    {
        return self::decodeTransferAssetStreamData($item['data'] ?? null, ($item['encoding'] ?? null) === 'base64' ? 'base64' : null);
    }

    /** @return list<int>|null */
    private static function getLegacyBufferJsonData(mixed $value): ?array
    {
        if (!is_array($value) || ($value['type'] ?? null) !== 'Buffer') {
            return null;
        }
        $raw = $value['data'] ?? null;
        if (is_array($raw)) {
            return array_values(array_map(static fn (mixed $b): int => is_numeric($b) ? (int) $b : 0, $raw));
        }

        return null;
    }

    /**
     * Decode binary payload for `TransferAssetFlow` `action: 'stream'` after JSON.parse.
     *
     * Supported shapes: a base64 string (preferred), the legacy `{ type: 'Buffer', data: number[] }`.
     */
    public static function decodeTransferAssetStreamData(mixed $data, ?string $encoding = null): string
    {
        if ($encoding === 'base64' && is_string($data)) {
            return (string) base64_decode($data, false);
        }

        $legacyBufferData = self::getLegacyBufferJsonData($data);
        if ($legacyBufferData !== null) {
            return $legacyBufferData === [] ? '' : pack('C*', ...$legacyBufferData);
        }

        // Wire base64 string (pull generator and any other path that stringifies a string payload).
        if (is_string($data)) {
            return (string) base64_decode($data, false);
        }

        throw new \TypeError('Invalid transfer asset stream chunk payload');
    }

    /**
     * Approximate decoded byte size for batching (pull asset generator).
     *
     * @param array<string, mixed> $chunk
     */
    public static function transferAssetStreamChunkByteLength(array $chunk): int
    {
        if (($chunk['action'] ?? null) !== 'stream') {
            return 0;
        }
        $data = $chunk['data'] ?? null;
        if (is_string($data)) {
            return intdiv(strlen($data) * 3, 4);
        }

        $legacyBufferData = self::getLegacyBufferJsonData($data);
        if ($legacyBufferData !== null) {
            return count($legacyBufferData);
        }

        return 0;
    }
}
