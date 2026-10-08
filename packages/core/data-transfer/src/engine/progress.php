<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Engine;

use Strapi\DataTransfer\Utils\Stream\EventEmitter;

/**
 * Not an upstream file: the engine's `progress` object (`{ data, stream }`): `data` holds the
 * per-stage metrics, `stream` emits the `transfer::*` and `stage::*` events.
 *
 * @phpstan-type StageProgress array{count: int, bytes: int, startTime: int, endTime?: int, totalBytes?: int, totalCount?: int, aggregates?: array<string, array{count: int, bytes: int}>}
 */
final class Progress
{
    /** @var array<string, StageProgress> */
    public array $data = [];

    public function __construct(public readonly EventEmitter $stream = new EventEmitter())
    {
    }
}
