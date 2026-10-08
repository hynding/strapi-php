<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests;

use Psr\Log\AbstractLogger;

/** A PSR-3 logger that keeps what it is given. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message];
    }

    /** @return list<string> */
    public function messages(string $level): array
    {
        return array_values(array_map(static fn (array $r): string => $r[1], array_filter($this->records, static fn (array $r): bool => $r[0] === $level)));
    }
}
