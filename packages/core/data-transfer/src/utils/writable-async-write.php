<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

use Strapi\DataTransfer\Utils\Stream\Writable;

/**
 * Port of src/utils/writable-async-write.ts. Upstream waits for the write callback and for
 * `drain` under backpressure; writes are synchronous here, so this is `writable.write(chunk)`.
 */
final class WritableAsyncWrite
{
    public static function write(Writable $writable, mixed $chunk): void
    {
        $writable->write($chunk);
    }
}
