<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\Engine\Engine;
use Strapi\DataTransfer\Engine\Errors\TransferEngineValidationError;
use Strapi\DataTransfer\Tests\Engine\Fake\Data;
use Strapi\DataTransfer\Tests\Engine\Fake\FakeDestination;
use Strapi\DataTransfer\Tests\Engine\Fake\FakeSource;
use Strapi\DataTransfer\Tests\Engine\Fake\MinimalDestination;
use Strapi\DataTransfer\Tests\Engine\Fake\MinimalSource;
use Strapi\DataTransfer\Tests\Engine\Fake\PartialSource;

/**
 * Port of src/engine/__tests__/engine.test.ts. The jest mock providers are the fakes of
 * tests/Engine/Fake; backpressure, heap-growth and stream-destruction cases have no synchronous
 * PHP equivalent and are not ported.
 */
final class EngineTest extends TestCase
{
    private const array DEFAULT_OPTIONS = ['versionStrategy' => 'exact', 'schemaStrategy' => 'exact', 'exclude' => []];

    private const array SOURCE_STAGES = ['createEntitiesReadStream', 'createLinksReadStream', 'createAssetsReadStream', 'createConfigurationReadStream', 'createSchemasReadStream'];

    private const array DESTINATION_STAGES = ['createEntitiesWriteStream', 'createLinksWriteStream', 'createAssetsWriteStream', 'createConfigurationWriteStream', 'createSchemasWriteStream'];

    /** @param list<string> $calls */
    private static function countCalls(array $calls, string $name): int
    {
        return count(array_filter($calls, static fn (string $c): bool => $c === $name));
    }

    private static function assertThrows(\Closure $fn, ?string $message = null): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($message !== null) {
                self::assertSame($message, $e->getMessage());
            } else {
                self::assertInstanceOf(\Throwable::class, $e);
            }

            return $e;
        }
        self::fail('Expected an exception');
    }

    public function testCreatesAValidTransferEngine(): void
    {
        $engine = Engine::createTransferEngine(new MinimalSource(), new MinimalDestination(), self::DEFAULT_OPTIONS);

        self::assertInstanceOf(Engine::class, $engine);
        self::assertSame([], $engine->progress->data);
    }

    public function testThrowsWhenGivenAnInvalidSourceProvider(): void
    {
        self::assertThrows(static fn () => new Engine(new class () extends MinimalSource {
            public string $type = 'destination';
        }, new MinimalDestination(), self::DEFAULT_OPTIONS));
    }

    public function testThrowsWhenGivenAnInvalidDestinationProvider(): void
    {
        self::assertThrows(static fn () => new Engine(new MinimalSource(), new class () extends MinimalDestination {
            public string $type = 'source';
        }, self::DEFAULT_OPTIONS));
    }

    public function testWorksForProvidersWithoutABootstrap(): void
    {
        $result = Engine::createTransferEngine(new MinimalSource(), new MinimalDestination(), self::DEFAULT_OPTIONS)->transfer();

        self::assertSame([], $result['engine']);
    }

    public function testCallsAllProviderStages(): void
    {
        $source = new FakeSource();
        $destination = new FakeDestination();
        $engine = Engine::createTransferEngine($source, $destination, self::DEFAULT_OPTIONS);
        self::assertSame([], $source->calls);

        $engine->transfer();

        foreach (self::SOURCE_STAGES as $stage) {
            self::assertSame(1, self::countCalls($source->calls, $stage), $stage);
        }
        foreach (self::DESTINATION_STAGES as $stage) {
            self::assertSame(1, self::countCalls($destination->calls, $stage), $stage);
        }
        // entities reach the destination in order; attributes the schemas do not declare are dropped
        self::assertSame(array_map(static fn (array $e): array => [$e['id'], $e['type']], Data::ENTITIES), array_map(static fn (array $e): array => [$e['id'], $e['type']], $destination->written['entities']));
        self::assertSame(['foo' => 'bar'], $destination->written['entities'][0]['data']);
        self::assertSame(['123', '456789'], array_column($destination->written['assets'], 'stream'));
    }

    public function testValidatesIncludedSourceStagesBeforePreparingTheDestination(): void
    {
        $source = new FakeSource();
        $source->onValidateStage = static function (string $stage): void {
            if ($stage === 'assets') {
                throw new \RuntimeException('invalid asset archive');
            }
        };
        $destination = new FakeDestination();

        self::assertThrows(static fn () => Engine::createTransferEngine($source, $destination, self::DEFAULT_OPTIONS)->transfer(), 'invalid asset archive');

        self::assertContains('validateStage:assets', $source->calls);
        self::assertNotContains('beforeTransfer', $destination->calls);
    }

    public function testClosesBothProvidersWhenStageValidationFails(): void
    {
        $source = new FakeSource();
        $source->onValidateStage = static fn () => throw new \RuntimeException('invalid asset archive');
        $destination = new FakeDestination();

        self::assertThrows(static fn () => Engine::createTransferEngine($source, $destination, self::DEFAULT_OPTIONS)->transfer(), 'invalid asset archive');

        self::assertSame(1, self::countCalls($destination->calls, 'bootstrap'));
        self::assertContains('rollback', $destination->calls);
        self::assertSame(1, self::countCalls($source->calls, 'close'));
        self::assertSame(1, self::countCalls($destination->calls, 'close'));
        self::assertGreaterThan(array_search('rollback', $destination->calls, true), array_search('close', $destination->calls, true));
    }

    public function testReportsButDoesNotRethrowCleanupErrorsRaisedOnTheFailurePath(): void
    {
        $source = new FakeSource();
        $source->onValidateStage = static fn () => throw new \RuntimeException('invalid asset archive');
        $source->onClose = static fn () => throw new \RuntimeException('source close failed');
        $destination = new FakeDestination();
        $destination->onRollback = static fn () => throw new \RuntimeException('rollback failed');
        $engine = Engine::createTransferEngine($source, $destination, self::DEFAULT_OPTIONS);

        self::assertThrows(static fn () => $engine->transfer(), 'invalid asset archive');

        self::assertSame(1, self::countCalls($destination->calls, 'close'));
        self::assertCount(2, array_filter($engine->diagnostics->items(), static fn (array $item): bool => $item['kind'] === 'warning'));
    }

    public function testDoesNotRetryProviderCleanupWhenCloseItselfFails(): void
    {
        $source = new FakeSource();
        $source->onClose = static fn () => throw new \RuntimeException('source close failed');
        $destination = new FakeDestination();

        self::assertThrows(static fn () => Engine::createTransferEngine($source, $destination, self::DEFAULT_OPTIONS)->transfer(), 'source close failed');

        self::assertNotContains('rollback', $destination->calls);
        self::assertSame(1, self::countCalls($source->calls, 'close'));
        self::assertSame(1, self::countCalls($destination->calls, 'close'));
    }

    public function testDoesNotValidateAnExcludedSourceStage(): void
    {
        $source = new FakeSource();
        Engine::createTransferEngine($source, new FakeDestination(), [...self::DEFAULT_OPTIONS, 'exclude' => ['files']])->transfer();

        self::assertNotContains('validateStage:assets', $source->calls);
    }

    /** @return iterable<array{list<string>|null, list<string>, list<string>}> */
    public static function excludeCases(): iterable
    {
        $all = ['bootstrap', 'createSchemasWriteStream', 'createLinksWriteStream', 'createEntitiesWriteStream', 'createConfigurationWriteStream', 'createAssetsWriteStream'];
        yield 'undefined' => [null, $all, []];
        yield 'none' => [[], $all, []];
        yield 'files' => [['files'], ['bootstrap', 'createSchemasWriteStream', 'createLinksWriteStream', 'createEntitiesWriteStream', 'createConfigurationWriteStream'], ['createAssetsWriteStream']];
        yield 'content' => [['content'], ['bootstrap', 'createSchemasWriteStream', 'createAssetsWriteStream', 'createConfigurationWriteStream'], ['createLinksWriteStream', 'createEntitiesWriteStream']];
        yield 'content+config' => [['content', 'config'], ['bootstrap', 'createSchemasWriteStream', 'createAssetsWriteStream'], ['createLinksWriteStream', 'createEntitiesWriteStream', 'createConfigurationWriteStream']];
        yield 'all' => [['content', 'config', 'files'], ['bootstrap', 'createSchemasWriteStream'], ['createAssetsWriteStream', 'createLinksWriteStream', 'createEntitiesWriteStream', 'createConfigurationWriteStream']];
    }

    /**
     * @param list<string>|null $exclude
     * @param list<string> $mustBeCalled
     * @param list<string> $mustNotBeCalled
     */
    #[DataProvider('excludeCases')]
    public function testExcludeOptionIncludesCorrectStages(?array $exclude, array $mustBeCalled, array $mustNotBeCalled): void
    {
        $destination = new FakeDestination();
        Engine::createTransferEngine(new FakeSource(), $destination, [...self::DEFAULT_OPTIONS, 'exclude' => $exclude])->transfer();

        foreach ($mustBeCalled as $method) {
            self::assertSame(1, self::countCalls($destination->calls, $method), $method);
        }
        foreach ($mustNotBeCalled as $method) {
            self::assertSame(0, self::countCalls($destination->calls, $method), $method);
        }
    }

    /** @return iterable<array{list<string>|null, list<string>, list<string>}> */
    public static function onlyCases(): iterable
    {
        $all = ['bootstrap', 'createSchemasWriteStream', 'createLinksWriteStream', 'createEntitiesWriteStream', 'createConfigurationWriteStream', 'createAssetsWriteStream'];
        yield 'undefined' => [null, $all, []];
        yield 'none' => [[], $all, []];
        yield 'files' => [['files'], ['bootstrap', 'createSchemasWriteStream', 'createAssetsWriteStream'], ['createLinksWriteStream', 'createEntitiesWriteStream', 'createConfigurationWriteStream']];
        yield 'content' => [['content'], ['bootstrap', 'createSchemasWriteStream', 'createLinksWriteStream', 'createEntitiesWriteStream'], ['createAssetsWriteStream', 'createConfigurationWriteStream']];
        yield 'content+config' => [['content', 'config'], ['bootstrap', 'createSchemasWriteStream', 'createLinksWriteStream', 'createEntitiesWriteStream', 'createConfigurationWriteStream'], ['createAssetsWriteStream']];
        yield 'all' => [['content', 'config', 'files'], $all, []];
    }

    /**
     * @param list<string>|null $only
     * @param list<string> $mustBeCalled
     * @param list<string> $mustNotBeCalled
     */
    #[DataProvider('onlyCases')]
    public function testOnlyOptionIncludesCorrectStages(?array $only, array $mustBeCalled, array $mustNotBeCalled): void
    {
        $destination = new FakeDestination();
        Engine::createTransferEngine(new FakeSource(), $destination, [...self::DEFAULT_OPTIONS, 'only' => $only])->transfer();

        foreach ($mustBeCalled as $method) {
            self::assertSame(1, self::countCalls($destination->calls, $method), $method);
        }
        foreach ($mustNotBeCalled as $method) {
            self::assertSame(0, self::countCalls($destination->calls, $method), $method);
        }
    }

    public function testReturnsProviderResults(): void
    {
        $source = new class () extends MinimalSource {
            /** @var array<string, string> */
            public array $results = ['foo' => 'bar'];
        };
        $destination = new class () extends MinimalDestination {
            /** @var array<string, string> */
            public array $results = ['foo' => 'baz'];
        };

        $results = Engine::createTransferEngine($source, $destination, self::DEFAULT_OPTIONS)->transfer();

        self::assertSame(['foo' => 'bar'], $results['source']);
        self::assertSame(['foo' => 'baz'], $results['destination']);
    }

    public function testSurfacesErrorFromCreateAssetsReadStream(): void
    {
        $source = new FakeSource();
        $source->onCreateAssetsReadStream = static fn () => throw new \RuntimeException('Test error');

        self::assertThrows(static fn () => Engine::createTransferEngine($source, new FakeDestination(), self::DEFAULT_OPTIONS)->transfer(), 'Test error');
    }

    public function testEmitsTransferStartAndFinishEvents(): void
    {
        $engine = Engine::createTransferEngine(new FakeSource(), new FakeDestination(), self::DEFAULT_OPTIONS);
        $events = ['transfer::start' => 0, 'transfer::finish' => 0];
        foreach (array_keys($events) as $event) {
            $engine->progress->stream->on($event, static function () use (&$events, $event): void {
                $events[$event]++;
            });
        }

        $engine->transfer();

        self::assertSame(['transfer::start' => 1, 'transfer::finish' => 1], $events);
    }

    public function testEmitsStageProgressEvents(): void
    {
        $engine = Engine::createTransferEngine(new FakeSource(), new FakeDestination(), self::DEFAULT_OPTIONS);
        $progressEvents = [];
        $engine->progress->stream->on('stage::progress', static function (array $payload) use (&$progressEvents): void {
            self::assertContains($payload['stage'], Engine::TRANSFER_STAGES);
            $progressEvents[$payload['stage']] = ($progressEvents[$payload['stage']] ?? 0) + 1;
        });

        $engine->transfer();

        foreach (Engine::TRANSFER_STAGES as $stage) {
            self::assertGreaterThanOrEqual(1, $progressEvents[$stage] ?? 0, $stage);
        }
        // 3 + 6 chunks, plus one 'end' per asset
        self::assertSame(11, $progressEvents['assets']);
    }

    public function testEmitsStageStartAndFinishEvents(): void
    {
        $engine = Engine::createTransferEngine(new FakeSource(), new FakeDestination(), self::DEFAULT_OPTIONS);
        $calls = ['stage::start' => 0, 'stage::finish' => 0];
        foreach (array_keys($calls) as $event) {
            $engine->progress->stream->on($event, static function (array $payload) use (&$calls, $event): void {
                self::assertContains($payload['stage'], Engine::TRANSFER_STAGES);
                $calls[$event]++;
            });
        }

        $engine->transfer();

        self::assertSame(['stage::start' => 5, 'stage::finish' => 5], $calls);
    }

    public function testMergesSourceGetStageTotalsIntoAssetsProgressBeforeStageStart(): void
    {
        $source = new FakeSource();
        $source->stageTotals = ['totalBytes' => 12345, 'totalCount' => 7];
        $engine = Engine::createTransferEngine($source, new FakeDestination(), self::DEFAULT_OPTIONS);
        $assetsAtStart = null;
        $engine->progress->stream->on('stage::start', static function (array $payload) use (&$assetsAtStart): void {
            if ($payload['stage'] === 'assets') {
                $assetsAtStart = $payload['data']['assets'] ?? null;
            }
        });

        $engine->transfer();

        self::assertContains('getStageTotals:assets', $source->calls);
        self::assertIsArray($assetsAtStart);
        self::assertSame(12345, $assetsAtStart['totalBytes']);
        self::assertSame(7, $assetsAtStart['totalCount']);
        self::assertSame(0, $assetsAtStart['count']);
        self::assertSame(0, $assetsAtStart['bytes']);
    }

    public function testEmitsStageSkipEvents(): void
    {
        $engine = Engine::createTransferEngine(new PartialSource(), new FakeDestination(), self::DEFAULT_OPTIONS);
        $calls = 0;
        $engine->progress->stream->on('stage::skip', static function () use (&$calls): void {
            $calls++;
        });

        $engine->transfer();

        self::assertSame(3, $calls);
    }

    public function testRelationsInsideComponentsAreTransferred(): void
    {
        $destination = new FakeDestination();
        $engine = Engine::createTransferEngine(new FakeSource(), $destination, self::DEFAULT_OPTIONS);

        $engine->transferLinks();

        self::assertContains('createLinksWriteStream', $destination->calls);
        self::assertSame(Data::LINKS, $destination->written['links']);
    }

    public function testExactSchemaStrategyFailsWhenASchemaIsMissingOnEitherSide(): void
    {
        $source = new FakeSource();
        $source->schemas = [...Data::schemas(), 'foo' => ['foo' => 'bar']];
        self::assertThrows(static fn () => Engine::createTransferEngine($source, new FakeDestination(), self::DEFAULT_OPTIONS)->transfer());

        $destination = new FakeDestination();
        $destination->schemas = [...Data::schemas(), 'foo' => ['foo' => 'bar']];
        self::assertThrows(static fn () => Engine::createTransferEngine(new FakeSource(), $destination, self::DEFAULT_OPTIONS)->transfer());
    }

    public function testExactSchemaStrategyFailsOnADifferingNestedField(): void
    {
        $destination = new FakeDestination();
        $schemas = Data::schemas();
        $schemas['admin::permission']['attributes']['action']['minLength'] = 2;
        $destination->schemas = $schemas;

        self::assertThrows(static fn () => Engine::createTransferEngine(new FakeSource(), $destination, self::DEFAULT_OPTIONS)->transfer());
    }

    /** @return iterable<array{string, mixed}> */
    public static function ignorableAttributeProperties(): iterable
    {
        yield 'private' => ['private', true];
        yield 'required' => ['required', true];
        yield 'configurable' => ['configurable', true];
        yield 'default' => ['default', static fn () => null];
    }

    #[DataProvider('ignorableAttributeProperties')]
    public function testStrictSchemaStrategyDoesNotThrowOnIgnorableAttributeProperties(string $property, mixed $value): void
    {
        $destination = new FakeDestination();
        $schemas = Data::schemas();
        $schemas['api::homepage.homepage']['attributes']['createdAt'][$property] = $value;
        $destination->schemas = $schemas;

        $result = Engine::createTransferEngine(new FakeSource(), $destination, [...self::DEFAULT_OPTIONS, 'schemaStrategy' => 'strict'])->transfer();

        self::assertArrayHasKey('engine', $result);
    }

    public function testStrictSchemaStrategyThrowsOnRegularAttributeProperties(): void
    {
        $destination = new FakeDestination();
        $schemas = Data::schemas();
        $schemas['api::homepage.homepage']['attributes']['createdAt']['type'] = 'string';
        $destination->schemas = $schemas;

        $error = self::assertThrows(
            static fn () => Engine::createTransferEngine(new FakeSource(), $destination, [...self::DEFAULT_OPTIONS, 'schemaStrategy' => 'strict'])->transfer(),
            "Invalid schema changes detected during integrity checks (using the strict strategy). Please find a summary of the changes below:\n- api::homepage.homepage:\n  - Schema value changed at \"attributes.createdAt.type\": \"datetime\" (string) => \"string\" (string)",
        );
        self::assertInstanceOf(TransferEngineValidationError::class, $error);
    }

    /** @return iterable<array{string, list<string>, list<string>}> */
    public static function versionStrategies(): iterable
    {
        yield 'invalid' => ['exact', ['foo', 'z1.2.3', '1.2.3z'], []];
        yield 'exact' => ['exact', ['1.2.3-alpha', '1.2.4', '2.2.3'], ['1.2.3']];
        yield 'major' => ['major', ['2.2.3'], ['1.2.3', '1.3.4', '1.4.4-alpha']];
        yield 'minor' => ['minor', ['2.2.3', '1.4.3', '1.4.3-alpha'], ['1.2.3', '1.2.40', '1.2.4-alpha']];
        yield 'patch' => ['patch', ['1.2.4', '1.2.4-alpha', '2.2.3'], ['1.2.3']];
        yield 'ignore' => ['ignore', [], ['1.2.3', '1.3.4', '5.24.44-alpha']];
    }

    /**
     * @param list<string> $fail
     * @param list<string> $succeed
     */
    #[DataProvider('versionStrategies')]
    public function testVersionMatching(string $strategy, array $fail, array $succeed): void
    {
        $run = static function (string $version) use ($strategy): array {
            $source = new FakeSource();
            $source->metadata = ['createdAt' => Data::METADATA['createdAt'], 'strapi' => ['version' => $version]];

            return Engine::createTransferEngine($source, new FakeDestination(), [...self::DEFAULT_OPTIONS, 'versionStrategy' => $strategy])->transfer();
        };

        foreach ($fail as $version) {
            self::assertThrows(static fn () => $run($version));
        }
        foreach ($succeed as $version) {
            self::assertArrayHasKey('engine', $run($version), $version);
        }
    }

    public function testSemverDiff(): void
    {
        self::assertNull(Engine::semverDiff('1.2.3', '1.2.3'));
        self::assertSame('patch', Engine::semverDiff('1.2.3', '1.2.4'));
        self::assertSame('minor', Engine::semverDiff('1.2.3', '1.3.0'));
        self::assertSame('major', Engine::semverDiff('1.2.3', '2.0.0'));
        self::assertSame('prepatch', Engine::semverDiff('1.2.3', '1.2.4-alpha'));
    }
}
