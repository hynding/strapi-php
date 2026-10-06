<?php

declare(strict_types=1);

namespace Strapi\Logger\Formats;

use Monolog\Handler\FilterHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Strapi\Logger\Constants;

/**
 * Port of formats/level-filter.ts: `levelFilter('error', 'warn')` keeps only records whose level
 * label is one of the given ones. Winston expresses this as a format; in Monolog it wraps a handler.
 */
final class LevelFilter
{
    /** @var list<Level> */
    private readonly array $levels;

    public function __construct(string ...$levels)
    {
        $this->levels = array_values(array_unique(array_map(Constants::toMonologLevel(...), $levels), SORT_REGULAR));
    }

    /** @return list<Level> */
    public function levels(): array
    {
        return $this->levels;
    }

    public function accepts(Level $level): bool
    {
        return in_array($level, $this->levels, true);
    }

    /** Wrap a handler so it only receives the accepted levels. */
    public function wrap(HandlerInterface $handler): FilterHandler
    {
        return new FilterHandler($handler, $this->levels);
    }
}
