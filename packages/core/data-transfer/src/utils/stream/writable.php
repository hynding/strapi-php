<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Stream;

/**
 * Not an upstream file: the PHP stand-in for Node's object-mode `Writable`, which every
 * destination provider returns for a stage. Everything is synchronous: `write()` runs the
 * `write` callback (upstream's `write(chunk, encoding, callback)`, where `callback(err)` becomes
 * a thrown exception), `end()` runs `final` once, `destroy()` runs `destroy` once.
 *
 * A failing write or final destroys the stream with the error and rethrows it.
 */
class Writable
{
    public bool $destroyed = false;

    public bool $finished = false;

    public bool $closed = false;

    public ?\Throwable $error = null;

    /** @var array<string, list<callable(mixed): mixed>> */
    private array $listeners = [];

    /**
     * @param (\Closure(mixed): void)|null              $write
     * @param (\Closure(): void)|null                   $final
     * @param (\Closure(\Throwable|null): void)|null    $destroy
     */
    public function __construct(
        private readonly ?\Closure $write = null,
        private readonly ?\Closure $final = null,
        private readonly ?\Closure $destroy = null,
    ) {
    }

    public function write(mixed $chunk): void
    {
        if ($this->destroyed) {
            throw $this->error ?? new \RuntimeException('Cannot call write after a stream was destroyed');
        }
        if ($this->finished) {
            throw new \RuntimeException('write after end');
        }

        try {
            $this->doWrite($chunk);
        } catch (\Throwable $error) {
            $this->destroy($error);
            throw $error;
        }
    }

    public function end(): void
    {
        if ($this->finished || $this->destroyed) {
            if ($this->error !== null) {
                throw $this->error;
            }

            return;
        }

        try {
            $this->doFinal();
        } catch (\Throwable $error) {
            $this->destroy($error);
            throw $error;
        }

        $this->finished = true;
        $this->closed = true;
        $this->emit('finish');
        $this->emit('close');
    }

    public function destroy(?\Throwable $error = null): void
    {
        if ($this->destroyed) {
            return;
        }

        $this->destroyed = true;
        $this->error ??= $error;

        try {
            $this->doDestroy($error);
        } finally {
            $this->closed = true;
            if ($error !== null) {
                $this->emit('error', $error);
            }
            $this->emit('close');
        }
    }

    /** @param callable(mixed): mixed $listener */
    public function on(string $event, callable $listener): static
    {
        $this->listeners[$event][] = $listener;

        return $this;
    }

    protected function doWrite(mixed $chunk): void
    {
        if ($this->write !== null) {
            ($this->write)($chunk);
        }
    }

    protected function doFinal(): void
    {
        if ($this->final !== null) {
            ($this->final)();
        }
    }

    protected function doDestroy(?\Throwable $error): void
    {
        if ($this->destroy !== null) {
            ($this->destroy)($error);
        }
    }

    protected function emit(string $event, mixed $arg = null): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($arg);
        }
    }
}
