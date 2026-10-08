<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Engine\Fake;

use Strapi\DataTransfer\Types\Providers\IDestinationProvider;
use Strapi\DataTransfer\Utils\Stream\Writable;

/** engine.test.ts `createDestination()`: every stage write stream, recording calls and what was written. */
class FakeDestination implements IDestinationProvider
{
    public string $type = 'destination';

    public string $name = 'completeDestination';

    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, list<mixed>> */
    public array $written = [];

    /** @var array<string, mixed>|null */
    public ?array $metadata = Data::METADATA;

    /** @var array<string, mixed>|null */
    public ?array $schemas = null;

    public ?\Closure $onRollback = null;

    /** @var array<string, mixed>|null what `transfer()` returns as `destination` (e.g. `['file' => ['path' => ...]]`) */
    public ?array $results = null;

    public function __construct()
    {
        $this->schemas = Data::schemas();
    }

    public function getMetadata(): ?array
    {
        $this->calls[] = 'getMetadata';

        return $this->metadata;
    }

    /** @return array<string, mixed>|null */
    public function getSchemas(): ?array
    {
        $this->calls[] = 'getSchemas';

        return $this->schemas;
    }

    public function bootstrap(): void
    {
        $this->calls[] = 'bootstrap';
    }

    public function close(): void
    {
        $this->calls[] = 'close';
    }

    public function rollback(): void
    {
        $this->calls[] = 'rollback';
        if ($this->onRollback !== null) {
            ($this->onRollback)();
        }
    }

    public function beforeTransfer(): void
    {
        $this->calls[] = 'beforeTransfer';
    }

    private function stream(string $method, string $stage): Writable
    {
        $this->calls[] = $method;
        $this->written[$stage] ??= [];

        return new Writable(write: function (mixed $chunk) use ($stage): void {
            if ($stage === 'assets' && is_array($chunk) && is_iterable($chunk['stream'] ?? null)) {
                $chunk['stream'] = implode('', iterator_to_array($chunk['stream'], false));
            }
            $this->written[$stage][] = $chunk;
        });
    }

    public function createEntitiesWriteStream(): Writable
    {
        return $this->stream('createEntitiesWriteStream', 'entities');
    }

    public function createLinksWriteStream(): Writable
    {
        return $this->stream('createLinksWriteStream', 'links');
    }

    public function createAssetsWriteStream(): Writable
    {
        return $this->stream('createAssetsWriteStream', 'assets');
    }

    public function createConfigurationWriteStream(): Writable
    {
        return $this->stream('createConfigurationWriteStream', 'configuration');
    }

    public function createSchemasWriteStream(): Writable
    {
        return $this->stream('createSchemasWriteStream', 'schemas');
    }
}
