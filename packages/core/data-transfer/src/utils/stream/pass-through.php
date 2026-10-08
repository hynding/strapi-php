<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Stream;

/**
 * Not an upstream file: the PHP stand-in for a byte `PassThrough` that is written to before it is
 * read — the asset streams the remote push handler fills chunk by chunk as WebSocket frames come
 * in. Chunks are buffered; iterating yields what has been written (call it once the stream has
 * ended); `onEnd()` / `onError()` callbacks run when the writer ends or destroys it.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class PassThrough extends Writable implements \IteratorAggregate
{
    /** @var list<string> */
    private array $chunks = [];

    private int $bytes = 0;

    public function length(): int
    {
        return $this->bytes;
    }

    /** @param callable(): mixed $cb */
    public function onEnd(callable $cb): self
    {
        if ($this->finished) {
            $cb();

            return $this;
        }

        return $this->on('finish', static fn (): mixed => $cb());
    }

    /** @param callable(\Throwable): mixed $cb */
    public function onError(callable $cb): self
    {
        if ($this->destroyed && $this->error !== null) {
            $cb($this->error);

            return $this;
        }

        return $this->on('error', static function (mixed $error) use ($cb): void {
            if ($error instanceof \Throwable) {
                $cb($error);
            }
        });
    }

    /** @return \Generator<int, string> */
    public function getIterator(): \Generator
    {
        while ($this->chunks !== []) {
            yield array_shift($this->chunks);
        }
    }

    /** Everything written so far, as one string (the chunks are consumed). */
    public function contents(): string
    {
        $contents = implode('', $this->chunks);
        $this->chunks = [];

        return $contents;
    }

    protected function doWrite(mixed $chunk): void
    {
        $chunk = (string) $chunk;
        $this->chunks[] = $chunk;
        $this->bytes += strlen($chunk);
    }
}
