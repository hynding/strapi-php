<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

/**
 * Port of src/utils/transfer-websocket-json.ts: serialization of the data-transfer WebSocket frames.
 */
final class TransferWebsocketJson
{
    /**
     * The replacer: values JSON cannot carry as is are made JSON-safe (dates become ISO strings as
     * `Date.prototype.toJSON` does, objects their public properties).
     */
    public static function replacerForTransferWebSocket(mixed $value): mixed
    {
        if ($value instanceof \Closure) {
            return $value; // dropped (or `null` in a list) by Json::stringify, as JSON.stringify does
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }
        if ($value instanceof \JsonSerializable) {
            return self::replacerForTransferWebSocket($value->jsonSerialize());
        }
        if ($value instanceof \Throwable) {
            // JSON.stringify(new Error()) === '{}'
            return new \stdClass();
        }
        if ($value instanceof \ArrayObject) {
            return self::replacerForTransferWebSocket($value->getArrayCopy());
        }
        if ($value instanceof \stdClass) {
            return $value;
        }
        if (is_object($value)) {
            return self::replacerForTransferWebSocket(get_object_vars($value));
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::replacerForTransferWebSocket($v);
            }

            return $value;
        }
        if (is_float($value) && !is_finite($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Serialize a transfer WebSocket envelope. Never returns anything but a string.
     *
     * @param array<string, mixed> $payload
     */
    public static function stringifyTransferWebSocketPayload(array $payload): string
    {
        try {
            return Json::stringify(self::replacerForTransferWebSocket($payload));
        } catch (\JsonException $err) {
            throw new \TypeError("Transfer WebSocket payload could not be serialized to JSON: {$err->getMessage()}");
        }
    }
}
