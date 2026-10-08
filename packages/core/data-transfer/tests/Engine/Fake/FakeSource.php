<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Engine\Fake;

use Strapi\DataTransfer\Types\Providers\ISourceProvider;

/**
 * engine.test.ts `createSource()`: every stage stream, recording calls. Behaviour the upstream
 * tests swap in with `jest.fn()` is set through the public closures.
 */
class FakeSource implements ISourceProvider
{
    public string $type = 'source';

    public string $name = 'completeSource';

    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, mixed>|null */
    public ?array $metadata = Data::METADATA;

    /** @var array<string, mixed>|null */
    public ?array $schemas = null;

    public ?\Closure $onValidateStage = null;

    public ?\Closure $onClose = null;

    public ?\Closure $onCreateAssetsReadStream = null;

    /** @var array{totalBytes?: int, totalCount?: int}|null */
    public ?array $stageTotals = null;

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
        if ($this->onClose !== null) {
            ($this->onClose)();
        }
    }

    public function validateStage(string $stage): void
    {
        $this->calls[] = "validateStage:{$stage}";
        if ($this->onValidateStage !== null) {
            ($this->onValidateStage)($stage);
        }
    }

    /** @return array{totalBytes?: int, totalCount?: int}|null */
    public function getStageTotals(string $stage): ?array
    {
        $this->calls[] = "getStageTotals:{$stage}";

        return $this->stageTotals;
    }

    /** @return iterable<mixed> */
    public function createEntitiesReadStream(): iterable
    {
        $this->calls[] = 'createEntitiesReadStream';

        return Data::ENTITIES;
    }

    /** @return iterable<mixed> */
    public function createLinksReadStream(): iterable
    {
        $this->calls[] = 'createLinksReadStream';

        return Data::LINKS;
    }

    /** @return iterable<mixed> */
    public function createAssetsReadStream(): iterable
    {
        $this->calls[] = 'createAssetsReadStream';
        if ($this->onCreateAssetsReadStream !== null) {
            ($this->onCreateAssetsReadStream)();
        }

        return Data::assets();
    }

    /** @return iterable<mixed> */
    public function createConfigurationReadStream(): iterable
    {
        $this->calls[] = 'createConfigurationReadStream';

        return Data::CONFIGURATION;
    }

    /** @return iterable<mixed> */
    public function createSchemasReadStream(): iterable
    {
        $this->calls[] = 'createSchemasReadStream';

        return Data::schemaStream();
    }
}
