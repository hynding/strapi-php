<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Cli\Commands;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Engine\Engine;
use Strapi\DataTransfer\Tests\BootedAppTestCase;
use Strapi\DataTransfer\Tests\Engine\Fake\FakeDestination;
use Strapi\DataTransfer\Tests\Engine\Fake\FakeSource;
use Strapi\DataTransfer\Types\Providers\IDestinationProvider;
use Strapi\DataTransfer\Types\Providers\ISourceProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Upstream's export/import/transfer action tests mock `@strapi/data-transfer` and
 * `createStrapiInstance`; here the actions get their factories through `$deps`: the instance is
 * the booted data-transfer fixture app, every provider factory records its options and returns a
 * fake provider, and the engine is the real one (with the fakes, its transfer is instant).
 */
abstract class DataTransferCommandTestCase extends BootedAppTestCase
{
    /** @var array<string, list<array<string, mixed>>> factory name => the options of each call */
    protected array $created = [];

    /** @var list<array<string, mixed>> */
    protected array $engineOptions = [];

    protected BufferedOutput $output;

    protected string $cwd = '';

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->cwd = sys_get_temp_dir() . '/strapi-dts-cli-' . bin2hex(random_bytes(4));
        mkdir($this->cwd);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cwd . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->cwd);
    }

    protected static function input(): ArrayInput
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        return $input;
    }

    /** @return \Closure(array<string, mixed>): ISourceProvider */
    protected function sourceFactory(string $name): \Closure
    {
        return function (array $options) use ($name): ISourceProvider {
            $this->created[$name][] = $options;
            $source = new FakeSource();
            $source->metadata = null;
            $source->schemas = null;

            return $source;
        };
    }

    /** @return \Closure(array<string, mixed>): IDestinationProvider */
    protected function destinationFactory(string $name, ?array $results = null): \Closure
    {
        return function (array $options) use ($name, $results): IDestinationProvider {
            $this->created[$name][] = $options;
            $destination = new FakeDestination();
            $destination->metadata = null;
            $destination->schemas = null;
            $destination->results = $results;

            return $destination;
        };
    }

    /** @return array<string, mixed> */
    protected function commonDeps(): array
    {
        return [
            'createStrapiInstance' => static fn (): Strapi => self::strapi(),
            'createTransferEngine' => function (ISourceProvider $source, IDestinationProvider $destination, array $options): Engine {
                $this->engineOptions[] = $options;

                return Engine::createTransferEngine($source, $destination, $options);
            },
            'cwd' => $this->cwd,
        ];
    }

    /**
     * @param array<string, mixed> $subset
     * @param array<string, mixed> $actual
     */
    protected static function assertSubset(array $subset, array $actual): void
    {
        foreach ($subset as $key => $value) {
            self::assertArrayHasKey($key, $actual);
            self::assertSame($value, $actual[$key], "at {$key}");
        }
    }
}
